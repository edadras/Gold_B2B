<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Controllers;

use App\Modules\Custody\Application\AssayDispatchService;
use App\Modules\Custody\Application\MergeService;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Http\Requests\MergeLotsRequest;
use App\Modules\Custody\Http\Requests\SendToAssayRequest;
use App\Modules\Custody\Http\Requests\SplitLotRequest;
use App\Modules\Custody\Http\Resources\CustodyOperationResource;
use App\Modules\Custody\Http\Resources\MergeResultResource;
use App\Modules\Custody\Http\Resources\SplitResultResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operations that change the shape of the metal — split, merge, send to assay.
 *
 * All three carry 🔑 (`idempotency`): a dropped response on a mobile network
 * must not saw a bar in half twice. Split and merge additionally carry ✍️
 * (`transaction.sign`), mounted before `idempotency` so a refused code cannot
 * burn the key.
 */
final class LotOperationController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly GoldLotRepositoryInterface $lots,
        private readonly SplitService $splits,
        private readonly MergeService $merges,
        private readonly AssayDispatchService $assayDispatch,
    ) {
        parent::__construct($authorization);
    }

    /**
     * 🔑 ✍️ — split a lot into children.
     *
     * AUTHORISATION — both legs, tenancy first. The parent lot is resolved and
     * matched against the caller's organisation before anything else, and a
     * lot owned by another member answers 404 rather than 403, so the route
     * cannot be used to discover which lot ids exist. LOT_SPLIT_MERGE is then
     * checked against that same organisation: holding the permission is not
     * enough on its own, and neither is owning the lot — a VIEWER who owns the
     * metal still may not cut it up. A tenant scope alone would enforce
     * neither, and is bypassed by any raw query.
     */
    public function split(SplitLotRequest $request, int $lotId): JsonResponse
    {
        $lot = $this->ownedLotOrNotFound($request, $lotId);

        $this->permit($request, Permission::LOT_SPLIT_MERGE->value, $lot->ownerOrganizationId);

        $result = $this->splits->split($request->toCommand($lot->id, $this->userId($request)));

        return ApiResponse::item(new SplitResultResource($result), 201);
    }

    /**
     * 🔑 ✍️ — merge several lots into one.
     *
     * AUTHORISATION — both legs, and the tenancy leg runs over EVERY input.
     * One foreign lot in the list is enough to make the whole request a 404:
     * merging consumes the inputs, so accepting a list that is only mostly the
     * caller's would destroy another member's metal. Answering 404 rather than
     * 403 also keeps the endpoint from confirming which of the submitted ids
     * exist. LOT_SPLIT_MERGE is then checked against the owning organisation.
     */
    public function merge(MergeLotsRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        foreach ($request->lotIds() as $lotId) {
            $this->ownedLotOrNotFound($request, $lotId);
        }

        $this->permit($request, Permission::LOT_SPLIT_MERGE->value, $organizationId);

        $result = $this->merges->merge($request->toCommand($this->userId($request)));

        return ApiResponse::item(new MergeResultResource($result), 201);
    }

    /**
     * 🔑 — hand a lot to a laboratory for re-assay.
     *
     * AUTHORISATION — both legs, tenancy first, foreign lot ⇒ 404. The role
     * leg uses ASSAY_RECORD, the permission that governs the assay lifecycle
     * (OWNER, MANAGER, TREASURER, OPERATOR); LOT_VIEW would be too weak, since
     * this puts the lot ON_HOLD and takes it out of the market.
     */
    public function sendToAssay(SendToAssayRequest $request, int $lotId): JsonResponse
    {
        $lot = $this->ownedLotOrNotFound($request, $lotId);

        $this->permit($request, Permission::ASSAY_RECORD->value, $lot->ownerOrganizationId);

        $laboratoryId = $request->validated('laboratory_id');

        $operation = $this->assayDispatch->send(
            lotId: $lot->id,
            ownerOrganizationId: $lot->ownerOrganizationId,
            requestedByUserId: $this->userId($request),
            laboratoryId: $laboratoryId === null ? null : (int) $laboratoryId,
            reason: $request->validated('reason'),
        );

        return ApiResponse::item(new CustodyOperationResource($operation), 202);
    }

    private function ownedLotOrNotFound(Request $request, int $lotId): GoldLotSnapshot
    {
        $lot = $this->lots->find($lotId);

        /** @var GoldLotSnapshot */
        return $this->ownedOrNotFound(
            $lot,
            $lot?->ownerOrganizationId,
            $this->organizationId($request),
        );
    }
}
