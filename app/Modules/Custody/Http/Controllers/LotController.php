<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Controllers;

use App\Modules\Custody\Application\LineageViewService;
use App\Modules\Custody\Contracts\AssayReaderInterface;
use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Http\Requests\IndexLotsRequest;
use App\Modules\Custody\Http\Resources\AssayResource;
use App\Modules\Custody\Http\Resources\LotLineageResource;
use App\Modules\Custody\Http\Resources\LotResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/lots` — docs/05-api/02-endpoints.md §2.10. */
final class LotController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly GoldLotRepositoryInterface $lots,
        private readonly LineageViewService $lineage,
        private readonly AssayReaderInterface $assays,
    ) {
        parent::__construct($authorization);
    }

    /**
     * The caller's own lots, optionally filtered by status.
     *
     * AUTHORISATION — both legs. permit() asks Identity whether the caller's
     * roles grant LOT_VIEW *and* whether the organisation whose lots are being
     * listed is the caller's own. The repository call is scoped by the same id,
     * but that scoping is a query detail, not the control: a raw query or
     * withoutGlobalScope() bypasses an Eloquent scope entirely, so tenancy is
     * asserted explicitly here as well.
     */
    public function index(IndexLotsRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::LOT_VIEW->value, $organizationId);

        $lots = $this->lots->forOwner($organizationId, $request->statusFilter());

        return ApiResponse::collection(LotResource::collection($lots));
    }

    /**
     * AUTHORISATION — both legs, tenancy first. The lot is resolved by id and
     * matched against the caller's organisation before the role check, and a
     * lot belonging to another member answers 404, never 403: 403 would
     * confirm the id exists and turn this route into an oracle over every lot
     * on the platform. Only once the lot is known to be the caller's own is
     * LOT_VIEW checked against that same organisation.
     */
    public function show(Request $request, int $lotId): JsonResponse
    {
        $lot = $this->ownedLotOrNotFound($request, $lotId);

        $this->permit($request, Permission::LOT_VIEW->value, $lot->ownerOrganizationId);

        return ApiResponse::item(new LotResource($lot));
    }

    /**
     * Ancestors, descendants and the operations that produced them.
     *
     * AUTHORISATION — both legs, tenancy first, exactly as in show(): a
     * foreign lot id is 404. The genealogy that comes back deliberately omits
     * the owner of every ancestor and descendant — a merge input may have
     * belonged to someone else, and knowing where a bar came from is not a
     * licence to learn who held it.
     */
    public function lineage(Request $request, int $lotId): JsonResponse
    {
        $lot = $this->ownedLotOrNotFound($request, $lotId);

        $this->permit($request, Permission::LOT_VIEW->value, $lot->ownerOrganizationId);

        return ApiResponse::item(new LotLineageResource($this->lineage->forLot($lot)));
    }

    /**
     * Every certificate ever issued for the lot, newest first.
     *
     * AUTHORISATION — both legs, tenancy first; a foreign lot id is 404.
     */
    public function assays(Request $request, int $lotId): JsonResponse
    {
        $lot = $this->ownedLotOrNotFound($request, $lotId);

        $this->permit($request, Permission::LOT_VIEW->value, $lot->ownerOrganizationId);

        return ApiResponse::collection(AssayResource::collection($this->assays->historyForLot($lot->id)));
    }

    /**
     * Resolve a lot and prove it is the caller's, or 404.
     *
     * Missing and not-yours are the same answer on purpose (ApiController::
     * ownedOrNotFound) — anything else leaks the existence of lot ids.
     */
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
