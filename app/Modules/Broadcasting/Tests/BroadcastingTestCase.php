<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Contracts\BroadcastThrottle;
use App\Modules\Broadcasting\Infrastructure\ArrayBroadcastThrottle;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for the realtime layer's tests.
 *
 * NO LIVE SOCKET IS INVOLVED ANYWHERE IN THIS SUITE, on purpose. What is worth
 * testing here is which events are produced, on which channels, with which
 * payload, and who is allowed to subscribe — all of which are decided in PHP
 * before anything reaches a wire. Standing up Reverb to assert on frames would
 * test Reverb. So: Event::fake() for the event assertions, the `null`
 * broadcast driver so nothing is emitted, and the throttle exercised directly.
 *
 * The app key/secret are set here rather than in phpunit.xml because that file
 * is shared with every other module's suite and this is the only suite that
 * cares.
 */
abstract class BroadcastingTestCase extends TestCase
{
    use RefreshDatabase;

    protected const APP_KEY = 'test-app-key';

    protected const APP_SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('broadcasting.default', 'null');
        config()->set('broadcasting.connections.reverb.key', self::APP_KEY);
        config()->set('broadcasting.connections.reverb.secret', self::APP_SECRET);
    }

    /**
     * Swaps the Redis-backed throttle for the in-process one and hands it back,
     * so a test can freeze its clock. Redis itself is covered separately by
     * DepthThrottleTest, which uses the real driver.
     */
    protected function useArrayThrottle(int $windowMs = 100): ArrayBroadcastThrottle
    {
        $throttle = new ArrayBroadcastThrottle($windowMs);

        $this->app->instance(BroadcastThrottle::class, $throttle);

        return $throttle;
    }

    /** An ACTIVE member organisation. */
    protected function organization(): Organization
    {
        return Organization::factory()->active()->create();
    }

    /** The platform operator's own organisation (`is_platform`). */
    protected function platformOrganization(): Organization
    {
        return Organization::factory()->platform()->create();
    }

    /** @param list<RoleEnum> $roles */
    protected function member(Organization $organization, array $roles = [RoleEnum::TRADER]): User
    {
        return $this->makeUser($organization, $roles);
    }

    /** @param list<RoleEnum> $roles */
    protected function staff(array $roles = [RoleEnum::PLATFORM_ADMIN]): User
    {
        return $this->makeUser($this->platformOrganization(), $roles);
    }

    /** @param list<RoleEnum> $roles */
    private function makeUser(Organization $organization, array $roles): User
    {
        $this->seedRolesOnce();

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

    private bool $rolesSeeded = false;

    private function seedRolesOnce(): void
    {
        if ($this->rolesSeeded) {
            return;
        }

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->rolesSeeded = true;
    }
}
