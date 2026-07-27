<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Kyc\Application\KycSubmissionService;
use App\Modules\Kyc\Http\Resources\KycProfileResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/organization/kyc` — docs/05-api/02-endpoints.md §2.2. */
final class KycController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly KycSubmissionService $submissions,
    ) {
        parent::__construct($authorization);
    }

    /**
     * The dossier plus everything still outstanding.
     *
     * AUTHORISATION — both legs, as everywhere. permit() asks Identity whether
     * the caller's roles grant BALANCE_VIEW (the "may read our own member
     * record" permission every organisation role holds) AND whether the
     * organisation the record belongs to is the caller's own. Neither half is
     * sufficient by itself: a role check alone would let any member read any
     * dossier, and a tenant scope alone is not a control at all, because
     * withoutGlobalScope() or a raw query walks straight past an Eloquent
     * global scope. Here the resource organisation *is* the caller's, because
     * there is only one dossier a member can address — its own.
     */
    public function show(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        $profile = $this->submissions->profileFor($organizationId);

        return ApiResponse::item(new KycProfileResource(
            $profile,
            $this->submissions->missingItems($organizationId),
            $this->submissions->isComplete($organizationId),
        ));
    }

    /**
     * 👑 OWNER — hand the dossier to the compliance queue.
     *
     * AUTHORISATION — both legs. KYC_EDIT is the OWNER/MANAGER permission, and
     * it is checked against the organisation that owns the dossier, not merely
     * against the caller's role: an OWNER of member A must not be able to
     * submit member B's dossier even though the role name matches. The tenant
     * scope on the query cannot be trusted to do this on its own.
     *
     * An incomplete dossier raises IncompleteKycException, which the handler in
     * bootstrap/app.php already renders as a 422 KYC_INCOMPLETE envelope
     * carrying `missing_items` — no try/catch here.
     */
    public function submit(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::KYC_EDIT->value, $organizationId);

        $profile = $this->submissions->submit($organizationId, $this->userId($request));

        return ApiResponse::item(new KycProfileResource(
            $profile,
            $this->submissions->missingItems($organizationId),
            $this->submissions->isComplete($organizationId),
        ), 202);
    }
}
