<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Unit;

use App\Modules\Identity\Domain\OrganizationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive check of the transition table against
 * docs/11-appendix/02-state-machines.md §2.2. Every one of the 100 ordered
 * pairs is asserted, so adding a case to the enum without updating the table
 * fails loudly.
 */
final class OrganizationStatusTest extends TestCase
{
    /** The single source of truth this test compares the enum against. */
    private const EXPECTED = [
        'PENDING' => ['UNDER_REVIEW'],
        'UNDER_REVIEW' => ['VERIFIED', 'INFO_REQUIRED', 'REJECTED'],
        'INFO_REQUIRED' => ['UNDER_REVIEW'],
        'VERIFIED' => ['ACTIVE'],
        'ACTIVE' => ['RESTRICTED', 'SUSPENDED', 'CLOSING'],
        'RESTRICTED' => ['ACTIVE', 'SUSPENDED', 'CLOSING'],
        'SUSPENDED' => ['ACTIVE', 'CLOSING'],
        'CLOSING' => ['CLOSED'],
        'CLOSED' => [],
        'REJECTED' => [],
    ];

    public function test_every_ordered_pair_matches_the_specification(): void
    {
        $checked = 0;

        foreach (OrganizationStatus::cases() as $from) {
            $allowed = self::EXPECTED[$from->value]
                ?? self::fail("No expectation declared for {$from->value}");

            foreach (OrganizationStatus::cases() as $to) {
                $shouldAllow = in_array($to->value, $allowed, true);

                $this->assertSame(
                    $shouldAllow,
                    $from->canTransitionTo($to),
                    "{$from->value} -> {$to->value} should ".($shouldAllow ? 'be allowed' : 'be rejected'),
                );

                $checked++;
            }
        }

        $this->assertSame(count(OrganizationStatus::cases()) ** 2, $checked);
    }

    public function test_no_state_transitions_to_itself(): void
    {
        foreach (OrganizationStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} must not transition to itself",
            );
        }
    }

    public function test_final_states_are_rejected_and_closed_only(): void
    {
        $final = array_values(array_map(
            static fn (OrganizationStatus $s): string => $s->value,
            array_filter(OrganizationStatus::cases(), static fn (OrganizationStatus $s): bool => $s->isFinal()),
        ));

        sort($final);

        $this->assertSame(['CLOSED', 'REJECTED'], $final);
    }

    public function test_closing_exists_and_is_reachable_but_not_final(): void
    {
        $closing = OrganizationStatus::CLOSING;

        $this->assertFalse($closing->isFinal());
        $this->assertTrue($closing->canTransitionTo(OrganizationStatus::CLOSED));
        // CLOSING is the transitional state for a member that asked to leave
        // but still has obligations; settlement stays open, trading does not.
        $this->assertTrue($closing->canSettle());
        $this->assertFalse($closing->canTrade());
    }

    public function test_only_active_may_trade(): void
    {
        foreach (OrganizationStatus::cases() as $status) {
            $this->assertSame(
                $status === OrganizationStatus::ACTIVE,
                $status->canTrade(),
                "{$status->value} trading capability",
            );
        }
    }

    public function test_every_non_final_state_reaches_a_final_state(): void
    {
        foreach (OrganizationStatus::cases() as $start) {
            $seen = [];
            $queue = [$start];
            $reachedFinal = false;

            while ($queue !== []) {
                /** @var OrganizationStatus $current */
                $current = array_shift($queue);

                if (isset($seen[$current->value])) {
                    continue;
                }

                $seen[$current->value] = true;

                if ($current->isFinal()) {
                    $reachedFinal = true;
                    break;
                }

                foreach ($current->allowedTransitions() as $next) {
                    $queue[] = $next;
                }
            }

            $this->assertTrue($reachedFinal, "{$start->value} can never terminate");
        }
    }

    public function test_restricted_and_suspended_cancel_open_orders(): void
    {
        $this->assertTrue(OrganizationStatus::RESTRICTED->cancelsOpenOrdersWhenEntered());
        $this->assertTrue(OrganizationStatus::SUSPENDED->cancelsOpenOrdersWhenEntered());
        $this->assertFalse(OrganizationStatus::ACTIVE->cancelsOpenOrdersWhenEntered());
    }
}
