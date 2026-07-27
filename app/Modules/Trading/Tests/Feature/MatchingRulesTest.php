<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Trading\Domain\Exceptions\FillOrKillNotFilledException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderFill;
use App\Modules\Trading\Infrastructure\Models\Trade;
use App\Modules\Trading\Tests\TradingTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The matching rules of docs/03-domain/04-trading.md §4.4 that are not covered
 * by the worked example: self-trade prevention, the two aggressive
 * time-in-force behaviours, and price-time priority.
 */
final class MatchingRulesTest extends TradingTestCase
{
    use RefreshDatabase;

    private const THIRD_ORG = 355;

    private const THIRD_USER = 900;

    private const PRICE = 78_480_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG, self::THIRD_ORG);

        foreach ([self::SELLER_ORG, self::BUYER_ORG, self::THIRD_ORG] as $org) {
            $this->depositGold($org, 5_000_000);
            $this->depositRial($org, 500_000_000_000);
        }
    }

    // ── self-trade ───────────────────────────────────────────────────────────

    #[Test]
    public function an_organization_cannot_trade_with_itself(): void
    {
        $sell = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);

        // Same organisation, crossing price: without the filter this would fill.
        $buy = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::BUY, 100_000, self::PRICE + 20_000);

        $this->assertSame([], $buy->trades);
        $this->assertSame(0, Trade::query()->count());

        // Both orders are still resting, untouched.
        $this->assertSame(OrderStatus::OPEN, Order::query()->findOrFail($sell->order->id)->status);
        $this->assertSame(OrderStatus::OPEN, Order::query()->findOrFail($buy->order->id)->status);
    }

    #[Test]
    public function a_third_party_at_the_same_price_does_match(): void
    {
        // The mirror of the test above: proves the non-match was the identity
        // check and not some other filter accidentally excluding the order.
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);

        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, self::PRICE + 20_000);

        $this->assertCount(1, $buy->trades);
        $this->assertSame(self::PRICE, $buy->trades[0]->price_per_gram_rial);
    }

    // ── fill or kill ─────────────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function a_fill_or_kill_that_cannot_complete_leaves_no_trace(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);

        $ledgerEntriesBefore = DB::table('ledger_entries')->count();
        $rialBefore = $this->rialBalance(self::BUYER_ORG);

        // 250 g wanted, only 100 g on offer.
        $this->expectException(FillOrKillNotFilledException::class);

        try {
            $this->placeLimit(
                self::BUYER_ORG,
                self::BUYER_USER,
                Side::BUY,
                250_000,
                self::PRICE,
                TimeInForce::FOK,
            );
        } finally {
            // Not one row of any kind survives the rollback.
            $this->assertSame(0, Order::query()->where('organization_id', self::BUYER_ORG)->count());
            $this->assertSame(0, Trade::query()->count());
            $this->assertSame(0, OrderFill::query()->count());
            $this->assertSame($ledgerEntriesBefore, DB::table('ledger_entries')->count());
            $this->assertSame($rialBefore, $this->rialBalance(self::BUYER_ORG));
            $this->assertSame(0, $this->rialBalance(self::BUYER_ORG, Bucket::RESERVED));

            // And the maker it failed against is exactly as it was.
            $maker = Order::query()->where('organization_id', self::SELLER_ORG)->firstOrFail();
            $this->assertSame(0, $maker->filled_mg);
            $this->assertSame(OrderStatus::OPEN, $maker->status);
        }
    }

    #[Test]
    public function a_fill_or_kill_that_can_complete_executes_normally(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 250_000, self::PRICE);

        $buy = $this->placeLimit(
            self::BUYER_ORG,
            self::BUYER_USER,
            Side::BUY,
            250_000,
            self::PRICE,
            TimeInForce::FOK,
        );

        $this->assertCount(1, $buy->trades);
        $this->assertSame(OrderStatus::FILLED, $buy->order->status);
    }

    // ── immediate or cancel ──────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function an_ioc_order_fills_what_it_can_and_cancels_the_rest(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);

        $buy = $this->placeLimit(
            self::BUYER_ORG,
            self::BUYER_USER,
            Side::BUY,
            250_000,
            self::PRICE,
            TimeInForce::IOC,
        );

        $this->assertCount(1, $buy->trades);
        $this->assertSame(100_000, $buy->trades[0]->quantity_fine_mg);

        $order = Order::query()->findOrFail($buy->order->id);
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(100_000, $order->filled_mg);

        // The 150 g that never filled had its rial handed straight back, so
        // nothing of this order is still locked beyond the executed part.
        $this->assertSame(0, $order->outstandingReservation());
        $this->assertGreaterThan(0, $order->released_amount);

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    // ── price-time priority ──────────────────────────────────────────────────

    #[Test]
    public function at_the_same_price_the_earlier_order_fills_first(): void
    {
        $first = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
        $second = $this->placeLimit(self::THIRD_ORG, self::THIRD_USER, Side::SELL, 100_000, self::PRICE);

        // Only enough demand for one of them.
        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, self::PRICE);

        $this->assertCount(1, $buy->trades);
        $this->assertSame($first->order->id, $buy->trades[0]->sell_order_id);

        $this->assertSame(OrderStatus::FILLED, Order::query()->findOrFail($first->order->id)->status);
        $this->assertSame(OrderStatus::OPEN, Order::query()->findOrFail($second->order->id)->status);
    }

    #[Test]
    public function a_better_price_outranks_an_earlier_one(): void
    {
        $expensiveButEarly = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE + 20_000);
        $cheapButLate = $this->placeLimit(self::THIRD_ORG, self::THIRD_USER, Side::SELL, 100_000, self::PRICE);

        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, self::PRICE + 20_000);

        $this->assertCount(1, $buy->trades);
        $this->assertSame($cheapButLate->order->id, $buy->trades[0]->sell_order_id);
        $this->assertSame(self::PRICE, $buy->trades[0]->price_per_gram_rial);

        $this->assertSame(OrderStatus::OPEN, Order::query()->findOrFail($expensiveButEarly->order->id)->status);
    }

    #[Test]
    public function an_order_sweeps_several_levels_and_records_a_fill_for_each(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
        $this->placeLimit(self::THIRD_ORG, self::THIRD_USER, Side::SELL, 150_000, self::PRICE + 10_000);

        $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 250_000, self::PRICE + 10_000);

        $this->assertCount(2, $buy->trades);
        $this->assertSame(self::PRICE, $buy->trades[0]->price_per_gram_rial);
        $this->assertSame(self::PRICE + 10_000, $buy->trades[1]->price_per_gram_rial);

        $this->assertSame(OrderStatus::FILLED, Order::query()->findOrFail($buy->order->id)->status);
        $this->assertSame(2, OrderFill::query()->where('order_id', $buy->order->id)->count());
    }
}
