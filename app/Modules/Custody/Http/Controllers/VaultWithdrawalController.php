<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Controllers;

use App\Modules\Custody\Application\VaultQueryService;
use App\Modules\Custody\Application\VaultService;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Http\Requests\StoreWithdrawalRequest;
use App\Modules\Custody\Http\Resources\CustodyOperationResource;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/vault/withdrawals` — docs/05-api/02-endpoints.md §2.10.
 *
 * A withdrawal is a VAULT_OUT row in `custody_operations`, the mirror of a
 * deposit's VAULT_IN. Requesting one only reserves the lots; the metal moves
 * after a second user approves, a platform vault officer issues the waybill,
 * and the one-time code is presented at the counter (docs §6.4).
 */
final class VaultWithdrawalController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly VaultQueryService $queries,
        private readonly VaultService $vault,
    ) {
        parent::__construct($authorization);
    }

    /**
     * AUTHORISATION — both legs: VAULT_WITHDRAW granted by role, and the rows
     * listed belong to the caller's organisation. The query scope is not a
     * substitute for the second leg; a raw query would ignore it.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::VAULT_WITHDRAW->value, $organizationId);

        return ApiResponse::collection(
            CustodyOperationResource::collection($this->queries->withdrawalsFor($organizationId)),
        );
    }

    /**
     * 🔑 ✍️ — ask for metal back.
     *
     * AUTHORISATION — both legs: the role must grant VAULT_WITHDRAW, and the
     * command carries the caller's own organisation id, which VaultService
     * re-checks against every lot before reserving it (LotNotOwnedException).
     * Two independent checks on purpose: this is the endpoint that takes metal
     * out of the building.
     */
    public function store(StoreWithdrawalRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::VAULT_WITHDRAW->value, $organizationId);

        $operation = $this->vault->requestWithdrawal(
            $request->toCommand($organizationId, $this->userId($request)),
        );

        return ApiResponse::item(new CustodyOperationResource($operation), 201);
    }

    /**
     * 👑 — the dual-control second signature.
     *
     * AUTHORISATION — both legs, tenancy first. The operation is loaded by id
     * and matched against the caller's organisation: another member's
     * withdrawal id answers 404, not 403, so the endpoint cannot be used to
     * probe for operations. VAULT_WITHDRAW is then checked against that same
     * organisation.
     *
     * The third check is dual control itself, and it is NOT an authorisation
     * question: VaultService::approveWithdrawal refuses when the approver is
     * the requester, and the `chk_op_approver_differs` constraint refuses it
     * again in the database. A user with the permission approving their own
     * request would satisfy both legs above and still be exactly the fraud
     * this rule exists to stop.
     */
    public function approve(Request $request, int $operationId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $operation = $this->queries->findOperation($operationId);

        /** @var CustodyOperationModel $operation */
        $operation = $this->ownedOrNotFound(
            $operation,
            $operation?->organization_id === null ? null : (int) $operation->organization_id,
            $organizationId,
        );

        // This route approves withdrawals and nothing else. A deposit id that
        // happens to belong to the caller is 404 here, not a 422 from deeper
        // in: the resource simply does not exist at this address.
        if ($operation->operation_type !== CustodyOperationType::VAULT_OUT) {
            throw $this->notFound();
        }

        $this->permit($request, Permission::VAULT_WITHDRAW->value, (int) $operation->organization_id);

        $approved = $this->vault->approveWithdrawal((int) $operation->id, $this->userId($request));

        return ApiResponse::item(new CustodyOperationResource($approved));
    }
}
