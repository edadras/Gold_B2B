<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Kyc\Application\LicenseService;
use App\Modules\Kyc\Http\Requests\StoreLicenseRequest;
use App\Modules\Kyc\Http\Resources\BusinessLicenseResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/organization/licenses` — docs/05-api/02-endpoints.md §2.2. */
final class LicenseController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly LicenseService $licenses,
    ) {
        parent::__construct($authorization);
    }

    /**
     * AUTHORISATION — both legs. KYC_EDIT must be granted by the caller's roles
     * AND the organisation being read must be the caller's own. The where()
     * inside the service is a query detail, not the control: a raw query or
     * withoutGlobalScope() would bypass a scope, so tenancy is re-asserted here.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::KYC_EDIT->value, $organizationId);

        return ApiResponse::collection(
            BusinessLicenseResource::collection($this->licenses->forOrganization($organizationId)),
        );
    }

    /**
     * 👑 OWNER — register a licence (or a renewal, which is a new row).
     *
     * AUTHORISATION — both legs: the role must grant KYC_EDIT, and the licence
     * is written into the caller's own organisation, whose id is what permit()
     * is given. No organisation id is accepted from the request body, so there
     * is no path by which a licence could be filed against another member.
     */
    public function store(StoreLicenseRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::KYC_EDIT->value, $organizationId);

        /** @var array{license_no: string, issuing_union: string, issued_at: string, expires_at: string} $attributes */
        $attributes = $request->safe()->all();

        $license = $this->licenses->register($organizationId, $attributes);

        return ApiResponse::item(new BusinessLicenseResource($license), 201);
    }
}
