<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\AdminServiceProvider;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role as RoleModel;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * Registers AdminServiceProvider explicitly.
 *
 * `bootstrap/providers.php` enumerates its modules by hand and is off limits to
 * this change, so the provider is not in the application's list yet. Registering
 * it here means the module's own suite is runnable regardless — and it must
 * happen before the framework boots, because the provider is what registers the
 * module's migrations, views, middleware aliases and `/admin` routes.
 *
 * Test fixtures may reach for another module's models and factories; the
 * architecture suite exempts `/Tests/` from the encapsulation rule for exactly
 * this reason, and building an organisation with a role through HTTP instead
 * would test Identity, not Admin.
 */
abstract class AdminTestCase extends TestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../../../../bootstrap/app.php';

        $app->register(AdminServiceProvider::class);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A member organisation with one user holding the given roles.
     *
     * @param  list<Role>  $roles
     */
    protected function makeUser(Organization $organization, array $roles = []): User
    {
        /** @var User $user */
        $user = User::factory()->forOrganization($organization)->create();

        if ($roles !== []) {
            $roleIds = RoleModel::query()
                ->whereIn('name', array_map(static fn (Role $r): string => $r->value, $roles))
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

    /** The operator's own organisation — where platform staff live. */
    protected function platformOrganization(): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::factory()->platform()->create();

        return $organization;
    }

    protected function memberOrganization(string $name = 'طلافروشی نمونه'): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::factory()->active()->create(['display_name' => $name]);

        return $organization;
    }

    /** @param list<Role> $roles */
    protected function staffUser(array $roles, ?Organization $organization = null): User
    {
        return $this->makeUser($organization ?? $this->platformOrganization(), $roles);
    }
}
