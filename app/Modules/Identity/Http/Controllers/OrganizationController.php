<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Identity\Http\Resources\OrganizationResource;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Contracts\VerificationTierDirectory;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET|PUT /organization` — docs/05-api/02-endpoints.md §2.2. */
final class OrganizationController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly VerificationTierDirectory $tiers,
    ) {
        parent::__construct($authorization);
    }

    public function show(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // There is only ever one organisation a member may read — its own —
        // so the lookup is scoped by the caller's organization_id and the
        // permission check confirms the role. Both legs, as always.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        $organization = $this->ownedOrNotFound(
            Organization::query()->find($organizationId),
            $organizationId,
            $organizationId,
        );

        return ApiResponse::item(new OrganizationResource($organization, [
            'verification_tier' => $this->tiers->tierFor($organizationId),
        ]));
    }

    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // KYC_EDIT is the OWNER/MANAGER-only permission that governs the
        // member's own profile; PermissionChecker additionally allows it while
        // the organisation is still onboarding, which is exactly when the
        // address and phone tend to be corrected.
        $this->permit($request, Permission::KYC_EDIT->value, $organizationId);

        /** @var Organization $organization */
        $organization = $this->ownedOrNotFound(
            Organization::query()->find($organizationId),
            $organizationId,
            $organizationId,
        );

        $organization->fill($request->safe()->all());
        $organization->save();

        return ApiResponse::item(new OrganizationResource($organization, [
            'verification_tier' => $this->tiers->tierFor($organizationId),
        ]));
    }
}
