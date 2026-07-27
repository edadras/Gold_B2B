<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Unit;

use App\Modules\Trading\Domain\MarketSessionStatus;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OtcOfferStatus;
use App\Modules\Trading\Domain\RfqQuoteStatus;
use App\Modules\Trading\Domain\RfqStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive transition tests for every Trading state machine, as rule 7 of
 * docs/11-appendix/02-state-machines.md §2.14 requires: each machine is walked
 * over the full cartesian product of its states, and every pair is asserted
 * either allowed or refused against the table transcribed from the appendix.
 *
 * The expected edge sets below are written out longhand rather than derived
 * from allowedTransitions(). Deriving them would make the test tautological —
 * it would pass for any implementation, including a wrong one. These lists are
 * the appendix, retyped.
 */
final class StateMachineTest extends TestCase
{
    /**
     * §2.3 — Order.
     *
     * @return array<string, list<string>>
     */
    private const ORDER_EDGES = [
        'PENDING' => ['OPEN', 'REJECTED'],
        'OPEN' => ['PARTIALLY_FILLED', 'FILLED', 'CANCELLED', 'EXPIRED'],
        'PARTIALLY_FILLED' => ['FILLED', 'CANCELLED', 'EXPIRED'],
        'FILLED' => [],
        'CANCELLED' => [],
        'REJECTED' => [],
        'EXPIRED' => [],
    ];

    /**
     * §2.6 — Rfq.
     *
     * @return array<string, list<string>>
     */
    private const RFQ_EDGES = [
        'OPEN' => ['QUOTED', 'CANCELLED', 'EXPIRED'],
        'QUOTED' => ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'CANCELLED', 'EXPIRED'],
        'PARTIALLY_ACCEPTED' => ['ACCEPTED', 'EXPIRED'],
        'ACCEPTED' => [],
        'CANCELLED' => [],
        'EXPIRED' => [],
    ];

    /**
     * §2.6 — RfqQuote.
     *
     * @return array<string, list<string>>
     */
    private const RFQ_QUOTE_EDGES = [
        'PENDING' => ['ACCEPTED', 'REJECTED', 'WITHDRAWN', 'EXPIRED'],
        'ACCEPTED' => [],
        'REJECTED' => [],
        'WITHDRAWN' => [],
        'EXPIRED' => [],
    ];

    /**
     * §2.7 — OtcOffer. Note COUNTERED -> COUNTERED is a legal self-edge; the
     * five-round cap is enforced by OtcService, not by the machine.
     *
     * @return array<string, list<string>>
     */
    private const OTC_EDGES = [
        'PENDING' => ['ACCEPTED', 'COUNTERED', 'REJECTED', 'CANCELLED', 'EXPIRED'],
        'COUNTERED' => ['ACCEPTED', 'COUNTERED', 'REJECTED', 'EXPIRED'],
        'ACCEPTED' => [],
        'REJECTED' => [],
        'CANCELLED' => [],
        'EXPIRED' => [],
    ];

    /**
     * §2.10 — MarketSession.
     *
     * @return array<string, list<string>>
     */
    private const SESSION_EDGES = [
        'SCHEDULED' => ['PRE_OPEN', 'CLOSED'],
        'PRE_OPEN' => ['OPEN', 'CLOSED'],
        'OPEN' => ['PAUSED', 'CLOSED'],
        'PAUSED' => ['OPEN', 'CLOSED'],
        'CLOSED' => [],
    ];

    /** @return iterable<string, array{class-string, array<string, list<string>>}> */
    public static function machines(): iterable
    {
        yield 'Order (§2.3)' => [OrderStatus::class, self::ORDER_EDGES];
        yield 'Rfq (§2.6)' => [RfqStatus::class, self::RFQ_EDGES];
        yield 'RfqQuote (§2.6)' => [RfqQuoteStatus::class, self::RFQ_QUOTE_EDGES];
        yield 'OtcOffer (§2.7)' => [OtcOfferStatus::class, self::OTC_EDGES];
        yield 'MarketSession (§2.10)' => [MarketSessionStatus::class, self::SESSION_EDGES];
    }

    /**
     * @param  class-string  $enum
     * @param  array<string, list<string>>  $expected
     */
    #[Test]
    #[DataProvider('machines')]
    public function every_state_is_described(string $enum, array $expected): void
    {
        $cases = array_map(static fn (object $c): string => $c->value, $enum::cases());

        sort($cases);
        $described = array_keys($expected);
        sort($described);

        $this->assertSame($described, $cases, "{$enum} does not match the appendix's state list");
    }

    /**
     * The exhaustive part: every ordered pair of states, allowed or refused.
     *
     * @param  class-string  $enum
     * @param  array<string, list<string>>  $expected
     */
    #[Test]
    #[DataProvider('machines')]
    public function every_transition_matches_the_appendix(string $enum, array $expected): void
    {
        foreach ($enum::cases() as $from) {
            foreach ($enum::cases() as $to) {
                $shouldBeAllowed = in_array($to->value, $expected[$from->value], true);

                $this->assertSame(
                    $shouldBeAllowed,
                    $from->canTransitionTo($to),
                    sprintf(
                        '%s: %s -> %s should be %s',
                        $enum,
                        $from->value,
                        $to->value,
                        $shouldBeAllowed ? 'allowed' : 'refused',
                    ),
                );
            }
        }
    }

    /**
     * @param  class-string  $enum
     * @param  array<string, list<string>>  $expected
     */
    #[Test]
    #[DataProvider('machines')]
    public function final_states_are_exactly_the_ones_with_no_outgoing_edge(string $enum, array $expected): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertSame(
                $expected[$case->value] === [],
                $case->isFinal(),
                sprintf('%s: %s finality is wrong', $enum, $case->value),
            );
        }
    }

    /**
     * @param  class-string  $enum
     * @param  array<string, list<string>>  $expected
     */
    #[Test]
    #[DataProvider('machines')]
    public function allowed_transitions_lists_exactly_the_appendix_targets(string $enum, array $expected): void
    {
        foreach ($enum::cases() as $case) {
            $actual = array_map(
                static fn (object $target): string => $target->value,
                $case->allowedTransitions(),
            );

            sort($actual);
            $wanted = $expected[$case->value];
            sort($wanted);

            $this->assertSame($wanted, $actual, sprintf('%s: %s', $enum, $case->value));
        }
    }

    // ── the helpers the services lean on ─────────────────────────────────────

    #[Test]
    public function only_open_and_partially_filled_orders_are_matchable(): void
    {
        $this->assertSame(['OPEN', 'PARTIALLY_FILLED'], OrderStatus::matchable());

        foreach (OrderStatus::cases() as $status) {
            $this->assertSame(
                in_array($status->value, OrderStatus::matchable(), true),
                $status->isActive(),
                $status->value,
            );
        }
    }

    #[Test]
    public function pre_open_accepts_orders_but_does_not_match_them(): void
    {
        // The distinction of §4.8: "ثبت سفارش مجاز، تطبیق انجام نمی‌شود".
        $this->assertTrue(MarketSessionStatus::PRE_OPEN->acceptsOrders());
        $this->assertFalse(MarketSessionStatus::PRE_OPEN->matches());

        $this->assertTrue(MarketSessionStatus::OPEN->acceptsOrders());
        $this->assertTrue(MarketSessionStatus::OPEN->matches());

        // A pause stops new orders but never stops a member cancelling.
        $this->assertFalse(MarketSessionStatus::PAUSED->acceptsOrders());
        $this->assertTrue(MarketSessionStatus::PAUSED->allowsCancellation());
    }

    #[Test]
    public function a_countered_offer_may_be_countered_again(): void
    {
        // The one self-edge in any of these machines; the five-round cap that
        // bounds it lives in OtcService and is covered by OtcServiceTest.
        $this->assertTrue(OtcOfferStatus::COUNTERED->canTransitionTo(OtcOfferStatus::COUNTERED));
        $this->assertFalse(OtcOfferStatus::COUNTERED->canTransitionTo(OtcOfferStatus::CANCELLED));
    }
}
