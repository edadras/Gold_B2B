<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Tests\TestCase;

/**
 * Registers the module's service provider explicitly rather than relying on
 * bootstrap/providers.php, so the module's tests are runnable on their own
 * regardless of what the application has wired up.
 */
abstract class IdentityTestCase extends TestCase
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param  list<RoleEnum>  $roles */
    protected function makeUser(Organization $organization, array $roles = []): User
    {
        /** @var User $user */
        $user = User::factory()->forOrganization($organization)->create();

        if ($roles !== []) {
            $roleIds = Role::query()
                ->whereIn('name', array_map(static fn (RoleEnum $r): string => $r->value, $roles))
                ->pluck('id')
                ->all();

            $pivot = [];
            foreach ($roleIds as $roleId) {
                $pivot[$roleId] = [
                    'organization_id' => $organization->id,
                    'assigned_at' => now(),
                ];
            }

            $user->roles()->sync($pivot);
            $user->unsetRelation('roles');
            $user->forgetPermissionCache();
        }

        return $user;
    }
}
