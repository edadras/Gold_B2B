<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Risk\Application\CollateralService;
use App\Modules\Risk\Application\LimitUsageService;
use App\Modules\Risk\Application\RiskProfileService;
use App\Modules\Risk\Http\Resources\CollateralResource;
use App\Modules\Risk\Http\Resources\ExposureResource;
use App\Modules\Risk\Http\Resources\LimitUsageResource;
use App\Modules\Risk\Http\Resources\RiskProfileResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/risk/*` — the member's own view of its risk standing
 * (docs/05-api/02-endpoints.md §2.15).
 *
 * Every action here is scoped to the caller's own organisation: there is no
 * `{orgId}` in any of these paths, and a member's risk level, ceilings and
 * collateral are never visible to another member.
 */
final class RiskController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly RiskProfileService $profiles,
        private readonly LimitUsageService $usage,
        private readonly CollateralService $collaterals,
    ) {
        parent::__construct($authorization);
    }

    /** `GET /risk/profile` */
    public function profile(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH legs are checked here, as everywhere. `permit()` asks Identity's
        // AuthorizationGateway, which asserts (1) that the resource's
        // organisation — passed explicitly as the third argument — is the
        // caller's own, and (2) that the caller's roles grant BALANCE_VIEW.
        // Neither leg alone is enough: a role check on its own would let a
        // TRADER read another member's risk profile, and a tenant scope on its
        // own is not a security boundary at all, because withoutGlobalScope()
        // or a raw query walks straight past it. Tenancy is therefore
        // re-asserted here rather than trusted to the model.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item(
            new RiskProfileResource($this->profiles->profile($organizationId))
        );
    }

    /** `GET /risk/limits` — ceilings AND today's consumption. */
    public function limits(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Two legs again: tenancy (the organisation whose limits are being read
        // must be the caller's) and role (BALANCE_VIEW). The per-user half of
        // the answer is keyed on the caller's own user id, which the request's
        // token establishes — it is never taken from the payload.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item(new LimitUsageResource(
            $this->usage->limits($organizationId, $this->userId($request))
        ));
    }

    /** `GET /risk/exposure` — F18 open obligations plus F20 collateral coverage. */
    public function exposure(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs. Exposure figures are aggregates over the member's
        // settlements and orders, so a missing tenancy check would leak another
        // member's whole trading position; a missing role check would expose it
        // to a role that may not see balances at all.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item(new ExposureResource($this->usage->exposure($organizationId)));
    }

    /** `GET /risk/collaterals` — the caller's own pledges. */
    public function collaterals(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs: the organisation predicate inside pledgesFor() is the
        // tenancy filter, and this call is what makes it legitimate — the
        // filter proves the rows belong to the caller, the permission proves
        // the caller's role may see them. A query filter alone authorises
        // nothing.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::collection(
            CollateralResource::collection($this->collaterals->pledgesFor($organizationId))
        );
    }
}
