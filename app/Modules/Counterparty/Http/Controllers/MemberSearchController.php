<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Controllers;

use App\Modules\Counterparty\Application\MemberDirectoryService;
use App\Modules\Counterparty\Http\Requests\MemberSearchRequest;
use App\Modules\Counterparty\Http\Resources\MemberSearchResultResource;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `GET /members/search` — finding a counterparty for an OTC deal or an RFQ
 * (docs §2.11).
 *
 * This endpoint is deliberately NOT tenant-scoped on the resource side: its
 * whole purpose is to return *other* members. That is safe because of what it
 * returns, not because of who asks — `MemberSearchResult` is a hard allow-list
 * of five fields (organisation id, display name, city, type, status), so there
 * is no national id, legal id, mobile, email, risk level or balance anywhere in
 * the payload to leak. Platform organisations and the caller's own organisation
 * are both filtered out, and the two-character minimum on `q` stops the
 * endpoint from being used to page through the whole membership.
 */
final class MemberSearchController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly MemberDirectoryService $members,
    ) {
        parent::__construct($authorization);
    }

    public function search(MemberSearchRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH legs are still checked, even though the *results* are other
        // members. Leg 1 (tenancy): the organisation passed here is the
        // caller's own — the caller must be acting for a real, active member of
        // the platform before it may see the directory at all. Leg 2 (role):
        // ORDER_BOOK_VIEW, the market-discovery permission every organisation
        // role holds, so the check is about being a member in good standing
        // rather than about seniority.
        //
        // A tenant scope alone would have authorised nothing here: there is no
        // owning organisation on a directory row to scope against, which is
        // exactly why the permission check has to be explicit.
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value, $organizationId);

        $results = $this->members->search($request->term(), $organizationId, $request->limit());

        return ApiResponse::collection(MemberSearchResultResource::collection($results));
    }
}
