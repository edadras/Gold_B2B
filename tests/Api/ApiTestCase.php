<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base for every HTTP test.
 *
 * Deliberately lives in tests/ rather than in a module: the API surface spans
 * every module, and a base class inside (say) Identity/Tests could not be used
 * by Pricing, which is only permitted to depend on Shared. Files outside
 * app/Modules are not subject to the module dependency graph.
 *
 * Uses the standard Tests\TestCase, so RefreshDatabase migrates EVERY module's
 * tables via bootstrap/providers.php. Do not reintroduce a per-module
 * createApplication() override here — that is what made RefreshDatabase build
 * only one module's schema.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeOrganization(
        OrganizationStatus $status = OrganizationStatus::ACTIVE,
        array $overrides = [],
    ): Organization {
        /** @var Organization $organization */
        $organization = Organization::factory()->create([
            'status' => $status,
            'activated_at' => $status === OrganizationStatus::ACTIVE ? now() : null,
        ] + $overrides);

        return $organization;
    }

    /** @param list<RoleEnum> $roles */
    protected function makeUser(
        Organization $organization,
        array $roles = [RoleEnum::OWNER],
        array $overrides = [],
    ): User {
        /** @var User $user */
        $user = User::factory()->forOrganization($organization)->create([
            'status' => UserStatus::ACTIVE,
        ] + $overrides);

        if ($roles !== []) {
            $roleIds = Role::query()
                ->whereIn('name', array_map(static fn (RoleEnum $r): string => $r->value, $roles))
                ->pluck('id')
                ->all();

            $pivot = [];
            foreach ($roleIds as $roleId) {
                $pivot[$roleId] = ['organization_id' => $organization->id, 'assigned_at' => now()];
            }

            $user->roles()->sync($pivot);
        }

        $user->unsetRelation('roles');
        $user->forgetPermissionCache();

        return $user;
    }

    /**
     * Authenticate as a user by issuing a real Sanctum token.
     *
     * actingAs() with the sanctum guard would work too, but a real token
     * exercises the same code path the client does, including token abilities.
     */
    protected function actingAsUser(User $user): static
    {
        // Sanctum's RequestGuard memoises the user it resolved, and the guard
        // instance survives from one request to the next inside a single test.
        // Without this, switching identities mid-test silently keeps the first
        // caller — which would make a cross-tenant assertion pass for the wrong
        // reason.
        $this->app['auth']->forgetGuards();

        $token = $user->createToken(
            'test',
            $user->permissionNames() === [] ? ['*'] : $user->permissionNames(),
            now()->addHour(),
        );

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
        $this->withHeader('Accept', 'application/json');

        return $this;
    }

    /** Assert the documented success envelope of §1.4. */
    protected function assertEnvelope(TestResponse $response): void
    {
        $response->assertJsonStructure(['data', 'meta' => ['request_id', 'server_time']]);

        self::assertIsString($response->json('meta.request_id'));
        self::assertNotSame('', $response->json('meta.request_id'));
    }

    /** Assert the documented error envelope of §1.5. */
    protected function assertErrorEnvelope(TestResponse $response, string $code): void
    {
        $response->assertJsonStructure(['error' => ['code', 'message'], 'meta' => ['request_id', 'server_time']]);
        $response->assertJsonPath('error.code', $code);
    }

    protected function uuid(): string
    {
        return (string) Str::uuid();
    }
}
