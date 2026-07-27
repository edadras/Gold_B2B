<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Application\OrganizationLifecycleService;
use App\Modules\Identity\Application\PermissionChecker;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Infrastructure\EloquentIdentityDirectory;
use App\Modules\Identity\Infrastructure\IdentityAuthorizationGateway;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\TotpTransactionSigner;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Contracts\TransactionSigner;
use Illuminate\Support\Facades\Gate;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bindings(): array
    {
        return [
            IdentityDirectory::class => EloquentIdentityDirectory::class,
            OrganizationLifecycle::class => OrganizationLifecycleService::class,

            // Ports Shared's HTTP layer declares and Identity answers. Shared
            // may not depend on Identity, so the arrow is inverted here.
            AuthorizationGateway::class => IdentityAuthorizationGateway::class,
            TransactionSigner::class => TotpTransactionSigner::class,
        ];
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PermissionChecker::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerGates();
    }

    /**
     * One Gate per Permission, named after its dotted value.
     *
     * Each gate checks tenancy AND permission AND organisation status — a
     * global scope on the model is not sufficient, because a raw query or
     * withoutGlobalScope() bypasses it (docs/02-architecture/04-security.md §4.3).
     *
     * Usage: Gate::allows('order.create', $organization) or, when the resource
     * is not an Organization, pass its organization_id as an int.
     */
    private function registerGates(): void
    {
        $checker = $this->app->make(PermissionChecker::class);

        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                static function (User $user, Organization|int|null $resourceOrganization = null) use ($checker, $permission): bool {
                    return $checker->allows($user, $permission, $resourceOrganization);
                },
            );
        }

        // Platform admins bypass nothing implicitly: PLATFORM_ADMIN simply holds
        // every permission (see Role::permissions()), so it flows through the
        // same three checks as everyone else.
    }
}
