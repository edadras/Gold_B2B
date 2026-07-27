<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\AuthorityType;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\RepresentativeStatus;
use App\Modules\Identity\Http\Requests\StoreRepresentativeRequest;
use App\Modules\Identity\Http\Resources\RepresentativeResource;
use App\Modules\Identity\Infrastructure\Models\Representative;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/organization/representatives` — docs/05-api/02-endpoints.md §2.2. */
final class RepresentativeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::USER_MANAGE->value, $organizationId);

        $representatives = Representative::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get();

        return ApiResponse::collection(RepresentativeResource::collection($representatives));
    }

    public function store(StoreRepresentativeRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::USER_MANAGE->value, $organizationId);

        $data = $request->safe()->all();

        // A representative may be linked to a login, but only to one inside the
        // same member. Without this check an owner could bind their
        // representative record to a stranger's user id.
        if (isset($data['user_id'])) {
            $linkedOrganizationId = User::query()->whereKey($data['user_id'])->value('organization_id');

            $this->ownedOrNotFound(
                $linkedOrganizationId,
                $linkedOrganizationId === null ? null : (int) $linkedOrganizationId,
                $organizationId,
            );
        }

        $representative = new Representative;
        $representative->fill([
            'organization_id' => $organizationId,
            'user_id' => $data['user_id'] ?? null,
            'full_name' => $data['full_name'],
            'authority_type' => AuthorityType::from((string) $data['authority_type']),
            'daily_limit_mg' => $data['daily_limit_mg'] ?? null,
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'] ?? null,
            'document_id' => $data['document_id'] ?? null,
            'status' => RepresentativeStatus::PENDING,
        ]);
        $representative->setNationalId($data['national_id'] ?? null);
        $representative->save();

        return ApiResponse::item(new RepresentativeResource($representative), 201);
    }
}
