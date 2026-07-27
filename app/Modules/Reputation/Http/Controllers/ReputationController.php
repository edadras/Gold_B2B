<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reputation\Application\PublicProfileService;
use App\Modules\Reputation\Http\Resources\OwnReputationResource;
use App\Modules\Reputation\Http\Resources\PublicProfileResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reputation endpoints — `GET /members/{id}/reputation` (docs §2.11) and
 * `GET /reputation/me`.
 *
 * The two look symmetrical and are not: one is a public profile of somebody
 * else, the other is the caller's own detailed statistics. The tenancy rules
 * differ accordingly, and each is spelled out at its own authorisation site.
 */
final class ReputationController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly PublicProfileService $profiles,
    ) {
        parent::__construct($authorization);
    }

    /**
     * `GET /members/{id}/reputation` — the public reputation of any member.
     *
     * THIS ENDPOINT IS DELIBERATELY NOT TENANT-SCOPED, and that is the point of
     * it: its whole purpose is to let a member size up a *potential
     * counterparty* before agreeing to an OTC deal or answering an RFQ. Scoping
     * it to the caller's own organisation would make it useless, and returning
     * 404 for "not your organisation" would be wrong here — the resource is
     * public by design, so a member id that exists and one that does not both
     * answer 200 with a zeroed profile.
     *
     * Why that is safe: `PublicProfile::toArray()` is a hard whitelist, built
     * field by field in PublicProfileService from the snapshot and never from a
     * model. Everything docs/03-domain/14-reputation.md §14.9 forbids — every
     * balance, every trade price, every named counterparty, the AML flags, the
     * suspension reasons, the KYC data and the internal credit score — has no
     * field in that object to land in, so there is nothing here for a tenancy
     * check to protect. The counters that are exposed (trade count, aggregate
     * volume, on-time rate, dispute rate, distinct-counterparty *count*) are
     * exactly the reputation signals §14.3 intends a market to publish.
     *
     * The 404-for-cross-tenant rule this codebase applies everywhere else is
     * about resources that have an owner. A public profile does not.
     */
    public function show(Request $request, int $organizationId): JsonResponse
    {
        // Only ONE leg is a tenancy leg here, and it is about the *caller*, not
        // the subject: `permit()` with no third argument checks the caller's
        // own organisation, i.e. that whoever is asking is an authenticated
        // member in good standing. The role leg is ORDER_BOOK_VIEW, the
        // market-discovery permission every organisation role holds.
        //
        // The subject id is intentionally NOT passed as the resource
        // organisation: doing so would demand that the caller and the subject
        // be the same member, which would break the endpoint's entire purpose.
        // The safety here comes from the payload's allow-list, not from
        // tenancy — see the doc block above.
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value);

        return ApiResponse::item(new PublicProfileResource($this->profiles->profile($organizationId)));
    }

    /**
     * `GET /reputation/me` — the caller's own, richer statistics.
     *
     * This one IS tenant-scoped, because it is not a public profile: §14.9
     * gives a member the right to see the numerators and denominators behind
     * its own rates so it can challenge them, and those raw counters are not
     * something another member may read.
     */
    public function me(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH legs. Leg 1 (tenancy): the organisation is passed explicitly, so
        // the gateway confirms it is the caller's own — the id comes from the
        // token and can never be supplied by the client. Leg 2 (role):
        // BALANCE_VIEW, the read permission over the member's own figures.
        // Neither leg alone would do: a role check by itself would let any
        // TRADER read another member's detailed statistics if the id were ever
        // parameterised, and a tenant scope by itself is not an authorisation
        // check at all — withoutGlobalScope() or a raw query walks past it.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        return ApiResponse::item(
            new OwnReputationResource($this->profiles->ownStatistics($organizationId))
        );
    }
}
