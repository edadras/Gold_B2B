<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Controllers;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Risk\Application\UserLimitService;
use App\Modules\Risk\Http\Requests\UpdateUserLimitsRequest;
use App\Modules\Risk\Http\Resources\UserLimitResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `PUT /organization/users/{id}/limits` — 👑 OWNER, docs §2.2.
 *
 * The path sits under `/organization`, but the row being written is
 * `user_limits`, a Risk table enforced by RiskGuard's check 10, so the route is
 * declared in this module. Identity says who the user is; Risk says how much
 * they may move.
 */
final class UserLimitController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly UserLimitService $limits,
        private readonly IdentityDirectory $directory,
    ) {
        parent::__construct($authorization);
    }

    public function update(UpdateUserLimitsRequest $request, int $userId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // BOTH legs are checked, and both are necessary here.
        //
        // Leg 2 (role) is this call: USER_LIMIT_SET is held only by OWNER and
        // MANAGER, so a TRADER raising their own ceiling is 403 FORBIDDEN_ROLE.
        //
        // Leg 1 (tenancy) is checked twice over, because the target is
        // addressed by an id the caller supplied. `permit()` confirms the
        // acting organisation is the caller's own, and `ownedOrNotFound()`
        // below confirms the *target user* belongs to it. Without the second
        // check an owner could rewrite a stranger's trading ceiling by guessing
        // a user id — a role check alone cannot catch that, and neither can an
        // Eloquent global scope, which `withoutGlobalScope()` or any raw query
        // walks straight past. The check is written out explicitly for exactly
        // that reason.
        $this->permit($request, Permission::USER_LIMIT_SET->value, $organizationId);

        $target = $this->directory->findUser($userId);

        // A user id outside the caller's organisation is 404, never 403:
        // answering 403 would confirm the id exists and turn this endpoint into
        // a membership oracle over the platform's whole user table.
        $this->ownedOrNotFound($target, $target?->organizationId, $organizationId);

        return ApiResponse::item(new UserLimitResource(
            $this->limits->set($organizationId, $userId, $request->safe()->all())
        ));
    }
}
