<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Tests\Feature;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Application\LicenseExpiryService;
use App\Modules\Kyc\Domain\LicenseStatus;
use App\Modules\Kyc\Events\LicenseExpired;
use App\Modules\Kyc\Events\LicenseExpiring;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Tests\KycTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * The 30/10/1-day ladder and the automatic RESTRICT on expiry (docs §1.6).
 */
final class LicenseExpiryTest extends KycTestCase
{
    use RefreshDatabase;

    private LicenseExpiryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(LicenseExpiryService::class);
    }

    public function test_the_reminder_ladder_fires_each_rung_exactly_once(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $license = $this->license($organization, Carbon::parse('2026-06-30'));

        // Comfortably outside the ladder: nothing happens.
        $this->assertSame(0, $this->service->sendReminders(Carbon::parse('2026-05-01')));

        // 30 days out.
        $this->assertSame(1, $this->service->sendReminders(Carbon::parse('2026-05-31')));
        $this->assertTrue($license->fresh()->reminder_sent_30d);
        $this->assertSame(LicenseStatus::EXPIRING_SOON, $license->fresh()->status);

        // Running again the same day must not re-notify.
        $this->assertSame(0, $this->service->sendReminders(Carbon::parse('2026-05-31')));
        $this->assertSame(0, $this->service->sendReminders(Carbon::parse('2026-06-05')));

        // 10 days out.
        $this->assertSame(1, $this->service->sendReminders(Carbon::parse('2026-06-20')));
        $this->assertTrue($license->fresh()->reminder_sent_10d);

        // 1 day out.
        $this->assertSame(1, $this->service->sendReminders(Carbon::parse('2026-06-29')));
        $this->assertTrue($license->fresh()->reminder_sent_1d);

        // Three rungs, three events, with the right day counts.
        Event::assertDispatchedTimes(LicenseExpiring::class, 3);
        Event::assertDispatched(
            LicenseExpiring::class,
            fn (LicenseExpiring $e): bool => $e->daysRemaining === 30,
        );
        Event::assertDispatched(
            LicenseExpiring::class,
            fn (LicenseExpiring $e): bool => $e->daysRemaining === 1,
        );
    }

    public function test_a_licence_first_seen_inside_the_window_still_gets_a_warning(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->license($organization, Carbon::parse('2026-06-30'));

        // Five days out: the 30-day rung is long gone, so the 10-day rung fires.
        $this->assertSame(1, $this->service->sendReminders(Carbon::parse('2026-06-25')));

        Event::assertDispatched(
            LicenseExpiring::class,
            fn (LicenseExpiring $e): bool => $e->daysRemaining === 5,
        );
    }

    public function test_expiry_restricts_the_member_and_emits_the_event(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $license = $this->license($organization, Carbon::parse('2026-06-30'));

        $result = $this->service->enforceExpiries(Carbon::parse('2026-07-01'));

        $this->assertSame(['expired' => 1, 'restricted' => 1], $result);

        $fresh = $license->fresh();
        $this->assertSame(LicenseStatus::EXPIRED, $fresh->status);
        $this->assertTrue($fresh->expiry_enforced);

        $organization = $organization->fresh();
        $this->assertSame(OrganizationStatus::RESTRICTED, $organization->status);
        $this->assertStringContainsString('انقضای جواز کسب', (string) $organization->restriction_reason);

        // The restriction is recorded as a SYSTEM transition.
        $this->assertDatabaseHas('organization_status_events', [
            'organization_id' => $organization->id,
            'to_status' => OrganizationStatus::RESTRICTED->value,
            'actor_type' => 'SYSTEM',
        ]);

        Event::assertDispatched(
            LicenseExpired::class,
            fn (LicenseExpired $e): bool => $e->organizationId === (int) $organization->id
                && $e->organizationRestricted === true,
        );
    }

    public function test_expiry_is_enforced_only_once(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->license($organization, Carbon::parse('2026-06-30'));

        $this->service->enforceExpiries(Carbon::parse('2026-07-01'));
        $second = $this->service->enforceExpiries(Carbon::parse('2026-07-02'));

        $this->assertSame(['expired' => 0, 'restricted' => 0], $second);
        Event::assertDispatchedTimes(LicenseExpired::class, 1);
    }

    public function test_a_member_with_a_second_valid_licence_is_not_restricted(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->license($organization, Carbon::parse('2026-06-30'), 'OLD-1');
        $this->license($organization, Carbon::parse('2027-06-30'), 'NEW-1');

        $result = $this->service->enforceExpiries(Carbon::parse('2026-07-01'));

        $this->assertSame(['expired' => 1, 'restricted' => 0], $result);
        $this->assertSame(OrganizationStatus::ACTIVE, $organization->fresh()->status);

        Event::assertDispatched(
            LicenseExpired::class,
            fn (LicenseExpired $e): bool => $e->organizationRestricted === false,
        );
    }

    public function test_an_already_suspended_member_is_not_dragged_back_to_restricted(): void
    {
        Event::fake();

        $organization = Organization::factory()->status(OrganizationStatus::SUSPENDED)->create();
        $this->license($organization, Carbon::parse('2026-06-30'));

        $result = $this->service->enforceExpiries(Carbon::parse('2026-07-01'));

        // The licence still expires; the member's status does not soften.
        $this->assertSame(1, $result['expired']);
        $this->assertSame(0, $result['restricted']);
        $this->assertSame(OrganizationStatus::SUSPENDED, $organization->fresh()->status);
    }

    public function test_the_console_command_runs_the_whole_sweep(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->license($organization, Carbon::parse('2026-06-30'));

        $this->artisan('kyc:check-expiring-licenses', ['--as-of' => '2026-07-01'])
            ->assertExitCode(0);

        $this->assertSame(OrganizationStatus::RESTRICTED, $organization->fresh()->status);
    }

    public function test_the_console_command_supports_a_dry_run(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $this->license($organization, Carbon::parse('2026-06-30'));

        $this->artisan('kyc:check-expiring-licenses', ['--as-of' => '2026-07-01', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(OrganizationStatus::ACTIVE, $organization->fresh()->status);
        Event::assertNotDispatched(LicenseExpired::class);
    }

    private function license(Organization $organization, Carbon $expiresAt, string $no = 'LIC-1'): BusinessLicense
    {
        /** @var BusinessLicense $license */
        $license = BusinessLicense::query()->create([
            'organization_id' => $organization->id,
            'license_no' => $no,
            'issuing_union' => 'اتحادیه طلا و جواهر',
            'issued_at' => $expiresAt->copy()->subYear()->toDateString(),
            'expires_at' => $expiresAt->toDateString(),
            'status' => LicenseStatus::VALID->value,
        ]);

        return $license;
    }
}
