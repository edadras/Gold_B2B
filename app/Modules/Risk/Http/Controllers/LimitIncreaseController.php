<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Risk\Application\LimitIncreaseRequestService;
use App\Modules\Risk\Http\Requests\LimitIncreaseRequestRequest;
use App\Modules\Risk\Http\Resources\LimitIncreaseRequestResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/** `POST /risk/limit-increase-request` — 👑, docs §2.15. */
final class LimitIncreaseController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly LimitIncreaseRequestService $requests,
    ) {
        parent::__construct($authorization);
    }

    public function store(LimitIncreaseRequestRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH legs, and this one is a write. Leg 1 (tenancy): the request is
        // raised for the caller's own organisation, passed explicitly so the
        // gateway compares it against the token's organisation rather than
        // trusting the payload. Leg 2 (role): USER_LIMIT_SET is the
        // ceiling-setting permission, held only by OWNER and MANAGER, so a
        // TRADER asking for a bigger book is 403 FORBIDDEN_ROLE.
        //
        // A tenant scope could not have produced this outcome on its own: it
        // would happily let a VIEWER inside the right organisation file the
        // request, and any raw INSERT would bypass it entirely. The permission
        // is checked here, at the boundary, for that reason.
        //
        // There is no limit-increase-specific permission in the Permission
        // enum; USER_LIMIT_SET is the nearest, and its 👑 role set matches the
        // crown the endpoint table gives this route.
        $this->permit($request, Permission::USER_LIMIT_SET->value, $organizationId);

        $justification = $request->validated('justification');

        try {
            $increase = $this->requests->request(
                organizationId: $organizationId,
                userId: $this->userId($request),
                limitType: $request->limitType(),
                requestedValue: (int) $request->validated('requested_value'),
                justification: $justification === null ? null : (string) $justification,
            );
        } catch (InvalidArgumentException) {
            // The service refuses a "raise" that is not one. That is a fact
            // about the submitted value, so it belongs in field_errors rather
            // than as an opaque 500 — the only translation this controller does.
            return ApiResponse::error(
                'VALIDATION_FAILED',
                'داده ورودی نامعتبر است.',
                422,
                fieldErrors: ['requested_value' => ['مقدار درخواستی باید از سقف فعلی بیشتر باشد.']],
            );
        }

        return ApiResponse::item(new LimitIncreaseRequestResource($increase), 201);
    }
}
