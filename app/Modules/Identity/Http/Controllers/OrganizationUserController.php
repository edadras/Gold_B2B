<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AssignRolesService;
use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\InviteUserService;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Http\Requests\InviteUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRolesRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/organization/users` — docs/05-api/02-endpoints.md §2.2. */
final class OrganizationUserController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly InviteUserService $invitations,
        private readonly AssignRolesService $roles,
        private readonly AuthService $auth,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::USER_MANAGE->value, $organizationId);

        $users = User::query()
            ->with('roles')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get();

        return ApiResponse::collection(UserResource::collection($users));
    }

    public function store(InviteUserRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // InviteUserService re-checks USER_MANAGE and USER_ROLE_CHANGE against
        // the actor itself, so no permit() call is duplicated here — the
        // service is the authority for this one because the rule depends on
        // which roles are being handed out.
        $result = $this->invitations->invite(
            $actor,
            $request->safe()->except('roles'),
            $request->roleEnums(),
        );

        return ApiResponse::item([
            'user' => new UserResource($result['user']->load('roles')),
            // Delivered out of band in production; returned here so the owner
            // can pass it on and so tests have something to assert.
            'invitation_token' => app()->isProduction() ? null : $result['invitation_token'],
        ], 201);
    }

    public function updateRoles(UpdateUserRolesRequest $request, int $userId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::USER_ROLE_CHANGE->value, $organizationId);

        $target = $this->loadColleague($userId, $organizationId);

        $this->roles->sync($target, $request->roleEnums(), $this->userId($request));

        return ApiResponse::item(new UserResource($target->load('roles')));
    }

    /**
     * Deactivation, not deletion: a user who has placed orders is referenced by
     * audit rows and ledger entries that must keep resolving.
     */
    public function destroy(Request $request, int $userId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::USER_MANAGE->value, $organizationId);

        if ($userId === $this->userId($request)) {
            return ApiResponse::error(
                'OPERATION_NOT_PERMITTED',
                'نمی‌توانید حساب کاربری خودتان را غیرفعال کنید.',
                422,
                ['reason' => 'cannot_disable_self'],
            );
        }

        $target = $this->loadColleague($userId, $organizationId);

        $target->forceFill(['status' => UserStatus::DISABLED])->save();
        $this->auth->revokeAllSessions($target, 'user_disabled');

        return ApiResponse::noContent();
    }

    /**
     * A user id outside the caller's organisation is 404, not 403 — answering
     * 403 would confirm that the id exists somewhere on the platform.
     */
    private function loadColleague(int $userId, int $organizationId): User
    {
        /** @var User|null $user */
        $user = User::query()->with('roles')->find($userId);

        /** @var User */
        return $this->ownedOrNotFound(
            $user,
            $user === null ? null : (int) $user->organization_id,
            $organizationId,
        );
    }
}
