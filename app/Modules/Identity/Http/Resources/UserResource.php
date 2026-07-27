<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A user as the API exposes them.
 *
 * `national_id_enc`, `password_hash` and the 2FA secret are all in the model's
 * $hidden, but this resource whitelists rather than relying on that: a future
 * `makeVisible()` somewhere else must not be able to leak a national id.
 *
 * @mixin User
 */
final class UserResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => (int) $user->id,
            'organization_id' => (int) $user->organization_id,
            'branch_id' => $user->branch_id === null ? null : (int) $user->branch_id,
            'full_name' => (string) $user->full_name,
            'mobile' => (string) $user->mobile,
            'email' => $user->email,
            'status' => $user->status->value,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'roles' => array_map(static fn (RoleEnum $r): string => $r->value, $user->roleEnums()),
            'role_labels' => array_map(static fn (RoleEnum $r): string => $r->label(), $user->roleEnums()),
            'permissions' => $user->permissionNames(),
            'last_login_at' => Display::iso($user->last_login_at),
        ] + $this->display($request, [
            'last_login_at_jalali' => Display::jalali($user->last_login_at),
        ]);
    }
}
