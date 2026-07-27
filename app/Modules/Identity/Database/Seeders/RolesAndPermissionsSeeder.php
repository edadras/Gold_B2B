<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Materialises the RBAC matrix of docs/01-product/01-personas-roles.md §1.4
 * into the roles / permissions / permission_role tables.
 *
 * Idempotent: safe to re-run after adding an enum case. Existing grants are
 * reconciled rather than duplicated, so a permission removed from a role in
 * code is removed from the table too.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionIds = $this->seedPermissions();
            $this->seedRoles($permissionIds);
        });
    }

    /** @return array<string, int> permission name => id */
    private function seedPermissions(): array
    {
        $ids = [];

        foreach (PermissionEnum::cases() as $permission) {
            /** @var Permission $model */
            $model = Permission::query()->updateOrCreate(
                ['name' => $permission->value],
                [
                    'group' => $permission->group(),
                    'requires_dual_control' => $permission->requiresDualControl(),
                ],
            );

            $ids[$permission->value] = (int) $model->id;
        }

        return $ids;
    }

    /** @param  array<string, int>  $permissionIds */
    private function seedRoles(array $permissionIds): void
    {
        foreach (RoleEnum::cases() as $role) {
            /** @var Role $model */
            $model = Role::query()->updateOrCreate(
                ['name' => $role->value],
                [
                    'label' => $role->label(),
                    'is_platform_role' => $role->isPlatformRole(),
                ],
            );

            $grantIds = [];
            foreach ($role->permissions() as $permission) {
                // A member role must never carry a platform permission, even if
                // someone adds one to the matrix by mistake.
                if (! $role->isPlatformRole() && $permission->isPlatformScoped()) {
                    continue;
                }

                $grantIds[] = $permissionIds[$permission->value];
            }

            $model->permissions()->sync($grantIds);
        }
    }
}
