<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\OrganizationStateMachine;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Events\OrganizationActivated;
use App\Modules\Identity\Events\OrganizationRestricted;
use App\Modules\Identity\Events\OrganizationStatusChanged;
use App\Modules\Identity\Events\OrganizationSuspended;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\OrganizationStatusEvent;
use App\Modules\Identity\Tests\IdentityTestCase;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/**
 * Drives the real service against the database: every legal transition must
 * succeed and be recorded, every illegal one must throw and change nothing.
 */
final class OrganizationStateMachineTest extends IdentityTestCase
{
    use RefreshDatabase;

    private OrganizationStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = $this->app->make(OrganizationStateMachine::class);
    }

    public function test_every_illegal_transition_throws_and_leaves_the_row_untouched(): void
    {
        Event::fake();

        $attempted = 0;

        foreach (OrganizationStatus::cases() as $from) {
            foreach (OrganizationStatus::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    continue;
                }

                $organization = Organization::factory()->status($from)->create();

                try {
                    $this->machine->transition($organization, $to, reason: 'test');
                    $this->fail("{$from->value} -> {$to->value} should have been rejected");
                } catch (InvalidStateTransitionException $e) {
                    $this->assertSame($from->value, $e->from);
                    $this->assertSame($to->value, $e->to);
                    $this->assertSame('Organization', $e->entity);
                }

                $this->assertSame(
                    $from,
                    $organization->fresh()->status,
                    "{$from->value} must be unchanged after a rejected transition",
                );

                $attempted++;
            }
        }

        // 100 ordered pairs minus the 15 legal ones.
        $this->assertSame(85, $attempted);
    }

    public function test_every_legal_transition_succeeds_and_is_recorded(): void
    {
        Event::fake();

        $applied = 0;

        foreach (OrganizationStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                $organization = Organization::factory()->status($from)->create();

                $result = $this->machine->transition(
                    organization: $organization,
                    target: $to,
                    reason: 'legal transition test',
                );

                $this->assertSame($to, $result->status);
                $this->assertSame($to, $organization->fresh()->status);

                $event = OrganizationStatusEvent::query()
                    ->where('organization_id', $organization->id)
                    ->latest('id')
                    ->first();

                $this->assertNotNull($event, "no status event written for {$from->value} -> {$to->value}");
                $this->assertSame($from, $event->from_status);
                $this->assertSame($to, $event->to_status);
                $this->assertSame('legal transition test', $event->reason);

                $applied++;
            }
        }

        $this->assertSame(15, $applied);
    }

    public function test_activation_stamps_the_timestamp_and_emits_the_ledger_trigger(): void
    {
        Event::fake();

        $organization = Organization::factory()->status(OrganizationStatus::VERIFIED)->create();

        $this->machine->transition($organization, OrganizationStatus::ACTIVE, actorUserId: null);

        $this->assertNotNull($organization->fresh()->activated_at);

        Event::assertDispatched(
            OrganizationActivated::class,
            fn (OrganizationActivated $e): bool => $e->organizationId === (int) $organization->id,
        );
        Event::assertDispatched(OrganizationStatusChanged::class);
    }

    public function test_restriction_emits_the_event_trading_listens_for(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();

        $this->machine->transition(
            organization: $organization,
            target: OrganizationStatus::RESTRICTED,
            reason: 'انقضای جواز کسب',
        );

        $fresh = $organization->fresh();
        $this->assertNotNull($fresh->restricted_at);
        $this->assertSame('انقضای جواز کسب', $fresh->restriction_reason);

        // Identity must not cancel orders itself; it announces and stops.
        Event::assertDispatched(
            OrganizationRestricted::class,
            fn (OrganizationRestricted $e): bool => $e->organizationId === (int) $organization->id
                && $e->previousStatus === OrganizationStatus::ACTIVE->value
                && $e->reason === 'انقضای جواز کسب',
        );
    }

    public function test_suspension_emits_the_event_trading_listens_for(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();

        $this->machine->transition(
            organization: $organization,
            target: OrganizationStatus::SUSPENDED,
            reason: 'تخلف جدی',
        );

        $this->assertNotNull($organization->fresh()->suspended_at);

        Event::assertDispatched(
            OrganizationSuspended::class,
            fn (OrganizationSuspended $e): bool => $e->organizationId === (int) $organization->id,
        );
    }

    public function test_reinstating_clears_the_restriction_reason_but_keeps_activated_at(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();
        $originalActivation = $organization->fresh()->activated_at;

        $this->machine->transition($organization, OrganizationStatus::SUSPENDED, reason: 'تعلیق');
        $this->machine->transition($organization, OrganizationStatus::ACTIVE, reason: 'رفع تعلیق');

        $fresh = $organization->fresh();

        $this->assertNull($fresh->restriction_reason);
        $this->assertSame(
            $originalActivation?->toDateTimeString(),
            $fresh->activated_at?->toDateTimeString(),
            'activated_at is the member-since date and must not be reset',
        );
    }

    public function test_system_transitions_are_recorded_with_a_system_actor(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();

        $this->machine->transitionBySystem(
            organization: $organization,
            target: OrganizationStatus::RESTRICTED,
            reason: 'انقضای جواز',
            metadata: ['business_license_id' => 42],
        );

        $event = OrganizationStatusEvent::query()
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(OrganizationStateMachine::ACTOR_SYSTEM, $event->actor_type);
        $this->assertNull($event->actor_user_id);
        $this->assertSame(['business_license_id' => 42], $event->metadata);
    }

    public function test_transition_to_the_same_status_is_rejected(): void
    {
        Event::fake();

        $organization = Organization::factory()->active()->create();

        $this->expectException(InvalidStateTransitionException::class);

        $this->machine->transition($organization, OrganizationStatus::ACTIVE);
    }
}
