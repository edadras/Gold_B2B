<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Pricing\Events\CircuitBreakerTriggered;
use App\Modules\Pricing\Events\NoPriceAvailable;
use App\Modules\Trading\Application\CancelOrderService;
use App\Modules\Trading\Application\OrderBookReader;
use App\Modules\Trading\Application\OrderExpiryService;
use App\Modules\Trading\Domain\Exceptions\MarketClosedException;
use App\Modules\Trading\Domain\MarketSessionStatus;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Tests\TradingTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Session lifecycle, order cancellation and the public depth view — §4.8 and
 * §4.9 of docs/03-domain/04-trading.md.
 */
final class MarketLifecycleTest extends TradingTestCase
{
    use RefreshDatabase;

    private const THIRD_ORG = 355;

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

    // ── cancellation ─────────────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function cancelling_an_order_releases_the_whole_unfilled_reservation(): void
    {
        $order = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 250_000, self::PRICE)->order;

        $cancelled = $this->app->make(CancelOrderService::class)
            ->cancel($order->id, self::SELLER_ORG, self::SELLER_USER);

        $this->assertSame(OrderStatus::CANCELLED, $cancelled->status);
        $this->assertSame(5_000_000, $this->goldBalance(self::SELLER_ORG));
        $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function cancelling_a_partially_filled_order_releases_only_the_remainder(): void
    {
        $sell = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 250_000, self::PRICE)->order;

        // 100 g of it executes.
        $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, self::PRICE);

        $cancelled = $this->app->make(CancelOrderService::class)
            ->cancel($sell->id, self::SELLER_ORG, self::SELLER_USER);

        $this->assertSame(OrderStatus::CANCELLED, $cancelled->status);
        $this->assertSame(100_000, $cancelled->filled_mg);
        // 150 g came back; the executed 100 g stays committed to settlement.
        $this->assertSame(150_000, $cancelled->released_amount);
        $this->assertSame(100_000, $cancelled->consumed_amount);
        // 5,000,000 - 250,000 reserved + 150,000 released = 4,900,000; the
        // executed 100,000 stays locked for settlement.
        $this->assertSame(4_900_000, $this->goldBalance(self::SELLER_ORG));

        $this->assertSystemConserved();
    }

    // ── session lifecycle ────────────────────────────────────────────────────

    #[Test]
    public function closing_the_session_cancels_day_orders_and_keeps_gtc_ones(): void
    {
        $day = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE)->order;
        $gtc = $this->placeLimit(
            self::THIRD_ORG,
            900,
            Side::SELL,
            100_000,
            self::PRICE + 10_000,
            TimeInForce::GTC,
        )->order;

        $session = $this->sessions()->close($this->instrument()->id);

        $this->assertSame(MarketSessionStatus::CLOSED, $session?->status);
        $this->assertSame(OrderStatus::CANCELLED, Order::query()->findOrFail($day->id)->status);
        $this->assertSame(OrderStatus::OPEN, Order::query()->findOrFail($gtc->id)->status);

        // The cancelled DAY order's gold is back.
        $this->assertSame(5_000_000, $this->goldBalance(self::SELLER_ORG));
    }

    #[Test]
    public function a_gtd_order_expires_when_its_deadline_passes(): void
    {
        $order = $this->placeLimit(
            self::SELLER_ORG,
            self::SELLER_USER,
            Side::SELL,
            100_000,
            self::PRICE,
            TimeInForce::GTD,
        )->order;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        try {
            $this->assertSame(1, $this->app->make(OrderExpiryService::class)->expireDueOrders());
            $this->assertSame(OrderStatus::EXPIRED, Order::query()->findOrFail($order->id)->status);
            $this->assertSame(5_000_000, $this->goldBalance(self::SELLER_ORG));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function the_circuit_breaker_event_pauses_the_session(): void
    {
        event(new CircuitBreakerTriggered(
            instrumentId: $this->instrument()->id,
            referenceRial: 78_000_000,
            currentRial: 81_000_000,
            deviationBps: 384,
            thresholdBps: 300,
        ));

        $session = $this->sessions()->currentSession($this->instrument()->id);

        $this->assertSame(MarketSessionStatus::PAUSED, $session?->status);
        $this->assertNotNull($session?->resume_at);
        $this->assertStringContainsString('Circuit breaker', (string) $session?->pause_reason);
    }

    #[Test]
    public function a_pause_stops_new_orders_but_never_traps_a_resting_one(): void
    {
        // "امکان لغو سفارش، عدم امکان ثبت جدید" — §4.8. Both halves matter, and
        // the second is the one that costs money if it is wrong: a member
        // cannot be locked out of releasing collateral because the platform
        // paused the instrument.
        $order = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE)->order;

        $reservedWhileOpen = $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED);
        $this->assertSame(100_000, $reservedWhileOpen);

        $this->sessions()->pauseForCircuitBreaker($this->instrument()->id, 384, 300);

        // Entry is closed.
        $this->assertThrows(
            fn () => $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, self::PRICE),
            MarketClosedException::class,
        );

        // Cancellation is not, and it releases the reservation like any other.
        $cancelled = $this->app->make(CancelOrderService::class)
            ->cancel($order->id, self::SELLER_ORG, self::SELLER_USER);

        $this->assertSame(OrderStatus::CANCELLED, $cancelled->status);
        $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function a_missing_reference_price_pauses_the_market_only_when_it_should_halt(): void
    {
        $instrumentId = $this->instrument()->id;

        // A transient gap must not stop trading.
        event(new NoPriceAvailable('REFERENCE', 30, false));
        $this->assertSame(
            MarketSessionStatus::OPEN,
            $this->sessions()->currentSession($instrumentId)?->status,
        );

        event(new NoPriceAvailable('REFERENCE', 600, true));
        $session = $this->sessions()->currentSession($instrumentId);

        $this->assertSame(MarketSessionStatus::PAUSED, $session?->status);
        // Open-ended: someone has to decide the price feed is trustworthy again.
        $this->assertNull($session?->resume_at);
    }

    #[Test]
    public function a_suspended_organization_loses_its_open_orders(): void
    {
        $order = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE)->order;

        $event = 'App\Modules\Identity\Events\OrganizationSuspended';

        if (! class_exists($event)) {
            $this->markTestSkipped('Identity module is not present in this slice.');
        }

        event(new $event(
            self::SELLER_ORG,
            'ACTIVE',
            'AML review',
            null,
            CarbonImmutable::now()->toIso8601String(),
        ));

        $this->assertSame(OrderStatus::CANCELLED, Order::query()->findOrFail($order->id)->status);
        $this->assertSame(5_000_000, $this->goldBalance(self::SELLER_ORG));
    }

    // ── depth ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_depth_view_aggregates_by_price_and_never_names_a_counterparty(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 150_000, self::PRICE);
        $this->placeLimit(self::THIRD_ORG, 900, Side::SELL, 200_000, self::PRICE);
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 500_000, self::PRICE + 20_000);

        $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 300_000, self::PRICE - 60_000);

        $depth = $this->app->make(OrderBookReader::class)->depth($this->instrument());

        $this->assertCount(2, $depth->asks);
        $this->assertSame(self::PRICE, $depth->asks[0]->priceRial);
        $this->assertSame(350_000, $depth->asks[0]->quantityMg);
        $this->assertSame(2, $depth->asks[0]->orderCount);

        $this->assertCount(1, $depth->bids);
        $this->assertSame(self::PRICE - 60_000, $depth->bids[0]->priceRial);

        $this->assertSame(60_000, $depth->spread());

        // Nothing in the serialised payload identifies who is behind a level.
        $encoded = json_encode($depth);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('organization', $encoded);
        $this->assertStringNotContainsString((string) self::SELLER_ORG, $encoded);
    }

    #[Test]
    public function the_depth_view_is_capped_at_ten_levels(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->placeLimit(
                self::SELLER_ORG,
                self::SELLER_USER,
                Side::SELL,
                50_000,
                self::PRICE + $i * 10_000,
            );
        }

        $depth = $this->app->make(OrderBookReader::class)->depth($this->instrument(), 25);

        $this->assertCount(OrderBookReader::MAX_LEVELS, $depth->asks);
        // The ten best, not an arbitrary ten.
        $this->assertSame(self::PRICE, $depth->asks[0]->priceRial);
        $this->assertSame(self::PRICE + 9 * 10_000, $depth->asks[9]->priceRial);
    }

    #[Test]
    public function pre_open_accepts_an_order_without_matching_it(): void
    {
        $instrument = $this->instrument();

        // Rewind to a fresh session in PRE_OPEN.
        $this->sessions()->close($instrument->id);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());

        try {
            $this->sessions()->preOpen($instrument);

            $sell = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
            $buy = $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, self::PRICE);

            $this->assertSame(OrderStatus::OPEN, $sell->order->status);
            $this->assertSame(OrderStatus::OPEN, $buy->order->status);
            $this->assertSame([], $buy->trades);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
