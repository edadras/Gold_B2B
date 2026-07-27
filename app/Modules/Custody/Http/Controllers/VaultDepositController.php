<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Controllers;

use App\Modules\Custody\Application\VaultQueryService;
use App\Modules\Custody\Application\VaultService;
use App\Modules\Custody\Http\Requests\StoreDepositRequest;
use App\Modules\Custody\Http\Resources\CustodyOperationResource;
use App\Modules\Custody\Http\Resources\DepositResultResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/vault/deposits` — docs/05-api/02-endpoints.md §2.10.
 *
 * A deposit is a VAULT_IN row in `custody_operations`; there is no separate
 * deposits table, which is why the listing goes through VaultQueryService with
 * an operation type rather than through a repository of its own.
 */
final class VaultDepositController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly VaultQueryService $queries,
        private readonly VaultService $vault,
    ) {
        parent::__construct($authorization);
    }

    /**
     * AUTHORISATION — both legs. VAULT_DEPOSIT_REQUEST has to be granted by the
     * caller's roles AND the deposits being listed have to belong to the
     * caller's organisation, whose id is what the query is scoped by. The scope
     * is not the control — withoutGlobalScope() or a raw statement walks past
     * one — so tenancy is asserted here as well.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::VAULT_DEPOSIT_REQUEST->value, $organizationId);

        return ApiResponse::collection(
            CustodyOperationResource::collection($this->queries->depositsFor($organizationId)),
        );
    }

    /**
     * 🔑 — book metal into a vault.
     *
     * AUTHORISATION — both legs: the role must grant VAULT_DEPOSIT_REQUEST,
     * and the lots are created inside the caller's own organisation, whose id
     * is the one given to permit() and the only one that reaches the command.
     * The request body carries no organisation field, so a member cannot
     * deposit metal into another member's name.
     */
    public function store(StoreDepositRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::VAULT_DEPOSIT_REQUEST->value, $organizationId);

        $result = $this->vault->deposit($request->toCommand($organizationId, $this->userId($request)));

        return ApiResponse::item(new DepositResultResource($result), 201);
    }
}
