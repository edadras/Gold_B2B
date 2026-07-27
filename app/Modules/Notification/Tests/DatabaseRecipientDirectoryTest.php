<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Infrastructure\DatabaseRecipientDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The directory that reads Identity's tables.
 *
 * Skipped when Identity is not deployed in this slice — the dispatch rules are
 * covered against the in-memory directory, and this test is specifically about
 * the SQL that crosses the module boundary.
 */
final class DatabaseRecipientDirectoryTest extends NotificationTestCase
{
    private DatabaseRecipientDirectory $directoryUnderTest;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['organizations', 'users', 'roles', 'role_user'] as $table) {
            if (! Schema::hasTable($table)) {
                self::markTestSkipped("Identity's {$table} table is not present in this deployment slice.");
            }
        }

        $this->directoryUnderTest = new DatabaseRecipientDirectory;
    }

    #[Test]
    public function it_finds_active_users_by_role_within_one_organisation(): void
    {
        $this->organization(1, 'طلافروشی کریمی', '09120000001');
        $this->organization(2, 'بنکداری پارس', '09120000002');

        $ownerRoleId = $this->role('OWNER');
        $treasurerRoleId = $this->role('TREASURER');
        $viewerRoleId = $this->role('VIEWER');

        $owner = $this->user(1, 'مالک', '09121111111', organizationId: 1);
        $treasurer = $this->user(2, 'خزانه‌دار', '09121111112', organizationId: 1);
        $viewer = $this->user(3, 'مشاهده‌گر', '09121111113', organizationId: 1);
        $otherOrgOwner = $this->user(4, 'مالک دیگر', '09121111114', organizationId: 2);

        $this->assign($owner, $ownerRoleId, 1);
        $this->assign($treasurer, $treasurerRoleId, 1);
        $this->assign($viewer, $viewerRoleId, 1);
        $this->assign($otherOrgOwner, $ownerRoleId, 2);

        $recipients = $this->directoryUnderTest->usersWithRoles(1, ['OWNER', 'TREASURER']);

        self::assertEqualsCanonicalizing(
            [$owner, $treasurer],
            array_map(static fn ($r): int => $r->id, $recipients),
        );
    }

    #[Test]
    public function a_suspended_user_is_not_notified(): void
    {
        $this->organization(1, 'طلافروشی کریمی', '09120000001');
        $roleId = $this->role('OWNER');

        $active = $this->user(1, 'فعال', '09121111111', organizationId: 1);
        $suspended = $this->user(2, 'معلق', '09121111112', organizationId: 1, status: 'SUSPENDED');

        $this->assign($active, $roleId, 1);
        $this->assign($suspended, $roleId, 1);

        $recipients = $this->directoryUnderTest->usersWithRoles(1, ['OWNER']);

        self::assertSame([$active], array_map(static fn ($r): int => $r->id, $recipients));
    }

    #[Test]
    public function an_expired_role_assignment_no_longer_targets_the_user(): void
    {
        $this->organization(1, 'طلافروشی کریمی', '09120000001');
        $roleId = $this->role('TREASURER');

        $stillHolding = $this->user(1, 'خزانه‌دار', '09121111111', organizationId: 1);
        $expired = $this->user(2, 'خزانه‌دار سابق', '09121111112', organizationId: 1);

        $this->assign($stillHolding, $roleId, 1);
        $this->assign($expired, $roleId, 1, expiresAt: now()->subDay()->toDateTimeString());

        $recipients = $this->directoryUnderTest->usersWithRoles(1, ['TREASURER']);

        self::assertSame([$stillHolding], array_map(static fn ($r): int => $r->id, $recipients));
    }

    #[Test]
    public function push_tokens_travel_with_the_recipient(): void
    {
        $this->organization(1, 'طلافروشی کریمی', '09120000001');
        $roleId = $this->role('OWNER');
        $owner = $this->user(1, 'مالک', '09121111111', organizationId: 1);
        $this->assign($owner, $roleId, 1);

        DB::table('push_devices')->insert([
            ['user_id' => $owner, 'platform' => 'IOS', 'token' => 'token-live', 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $owner, 'platform' => 'ANDROID', 'token' => 'token-revoked', 'revoked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $recipient = $this->directoryUnderTest->find($owner);

        self::assertNotNull($recipient);
        self::assertSame(['token-live'], $recipient->pushTokens, 'a revoked device is not a destination');
        self::assertContains('OWNER', $recipient->roles);
        self::assertSame('09121111111', $recipient->mobile);
    }

    private function organization(int $id, string $name, string $mobile): void
    {
        DB::table('organizations')->insert([
            'id' => $id,
            'type' => 'LEGAL_ENTITY',
            'status' => 'ACTIVE',
            'display_name' => $name,
            'city' => 'تهران',
            'mobile' => $mobile,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function role(string $name): int
    {
        $existing = DB::table('roles')->where('name', $name)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('roles')->insertGetId([
            'name' => $name,
            'label' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(
        int $id,
        string $name,
        string $mobile,
        int $organizationId,
        string $status = 'ACTIVE',
    ): int {
        DB::table('users')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'full_name' => $name,
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password_hash' => 'x',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assign(int $userId, int $roleId, int $organizationId, ?string $expiresAt = null): void
    {
        DB::table('role_user')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'assigned_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }
}
