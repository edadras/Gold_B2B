<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Unit;

use App\Modules\Custody\Domain\Enums\LotStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive check of the GoldLot state machine against
 * docs/11-appendix/02-state-machines.md §2.5.
 *
 * The expectation table below is transcribed from the document, not from the
 * enum, so a change to the enum that is not also a change to the document
 * fails here.
 */
#[Group('custody')]
#[Group('state-machine')]
final class LotStatusTest extends TestCase
{
    /** @return array<string, list<string>> */
    private static function documented(): array
    {
        return [
            'UNDER_ASSAY' => ['AVAILABLE'],
            'AVAILABLE' => ['RESERVED', 'ON_HOLD', 'IN_TRANSIT', 'UNDER_ASSAY', 'CONSUMED', 'WITHDRAWN'],
            'RESERVED' => ['IN_SETTLEMENT', 'AVAILABLE', 'ON_HOLD'],
            'IN_SETTLEMENT' => ['AVAILABLE', 'RESERVED', 'ON_HOLD'],
            'IN_TRANSIT' => ['AVAILABLE', 'ON_HOLD'],
            'ON_HOLD' => ['AVAILABLE'],
            'WITHDRAWN' => ['AVAILABLE'],
            'CONSUMED' => [],
        ];
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function everyTransition(): iterable
    {
        $documented = self::documented();

        foreach (LotStatus::cases() as $from) {
            foreach (LotStatus::cases() as $to) {
                $legal = in_array($to->value, $documented[$from->value], true);

                yield "{$from->value} -> {$to->value}" => [$from->value, $to->value, $legal];
            }
        }
    }

    #[Test]
    #[DataProvider('everyTransition')]
    public function it_permits_exactly_the_documented_transitions(string $from, string $to, bool $legal): void
    {
        $result = LotStatus::from($from)->canTransitionTo(LotStatus::from($to));

        $this->assertSame(
            $legal,
            $result,
            $legal
                ? "{$from} -> {$to} is documented as legal but the enum refuses it"
                : "{$from} -> {$to} is not documented but the enum permits it",
        );
    }

    #[Test]
    public function every_status_reports_the_documented_target_set(): void
    {
        foreach (self::documented() as $from => $expected) {
            $actual = array_map(
                static fn (LotStatus $s): string => $s->value,
                LotStatus::from($from)->allowedTransitions(),
            );

            sort($expected);
            sort($actual);

            $this->assertSame($expected, $actual, "allowedTransitions() drifted for {$from}");
        }
    }

    #[Test]
    public function consumed_is_the_only_final_state(): void
    {
        foreach (LotStatus::cases() as $status) {
            $this->assertSame(
                $status === LotStatus::CONSUMED,
                $status->isFinal(),
                "isFinal() is wrong for {$status->value}",
            );
        }
    }

    #[Test]
    public function a_consumed_lot_can_never_come_back(): void
    {
        foreach (LotStatus::cases() as $target) {
            $this->assertFalse(
                LotStatus::CONSUMED->canTransitionTo($target),
                "CONSUMED must be terminal but allows {$target->value}",
            );
        }
    }

    #[Test]
    public function no_status_can_transition_to_itself(): void
    {
        foreach (LotStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} must not transition to itself",
            );
        }
    }

    #[Test]
    public function only_available_lots_are_allocatable(): void
    {
        foreach (LotStatus::cases() as $status) {
            $this->assertSame($status === LotStatus::AVAILABLE, $status->isAllocatable());
        }
    }

    #[Test]
    public function reserved_and_in_settlement_lots_cannot_be_sold_again(): void
    {
        $this->assertTrue(LotStatus::RESERVED->isEncumbered());
        $this->assertTrue(LotStatus::IN_SETTLEMENT->isEncumbered());
        $this->assertFalse(LotStatus::AVAILABLE->isEncumbered());
    }
}
