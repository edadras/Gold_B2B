<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\Exceptions\InsufficientBalanceException;
use App\Modules\Shared\Exceptions\LimitExceededException;
use App\Modules\Trading\Domain\Exceptions\InvalidOrderException;
use App\Modules\Trading\Domain\Exceptions\MarketClosedException;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Events\OrderRejected;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Tests\TradingTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Everything that stops an order before it reaches the book.
 *
 * All of these are refusals, not rollbacks: they happen in phase 1 of
 * PlaceOrderService, outside the transaction, so there is nothing to undo. The
 * exception is the balance check, which can only be made under a lock and
 * therefore fails inside the transaction — and takes the order row with it.
 */
final class OrderRejectionTest extends TradingTestCase
{
    use RefreshDatabase;

    private const PRICE = 78_480_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);
        $this->depositGold(self::SELLER_ORG, 1_000_000);
        $this->depositRial(self::BUYER_ORG, 100_000_000_000);
    }

    #[Test]
    public function an_order_is_refused_when_the_market_is_closed(): void
    {
        $this->sessions()->close($this->instrument()->id);

        $this->expectException(MarketClosedException::class);

        try {
            $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
        } finally {
            $this->assertSame(0, Order::query()->count());
        }
    }

    #[Test]
    public function an_order_is_refused_when_the_market_is_paused(): void
    {
        $this->sessions()->pauseForCircuitBreaker($this->instrument()->id, 400, 300);

        $this->expectException(MarketClosedException::class);
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
    }

    #[Test]
    public function an_order_is_refused_when_the_risk_guard_says_no(): void
    {
        $this->refuseAllRisk();

        $this->expectException(LimitExceededException::class);

        try {
            $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
        } finally {
            $this->assertSame(0, Order::query()->count());
            // Nothing was locked, because nothing got as far as the ledger.
            $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
        }
    }

    #[Test]
    public function a_rejection_announces_itself_with_the_reason_code(): void
    {
        $this->refuseAllRisk();
        Event::fake([OrderRejected::class]);

        try {
            $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
        } catch (LimitExceededException) {
            // expected
        }

        Event::assertDispatched(
            OrderRejected::class,
            static fn (OrderRejected $e): bool => $e->reasonCode === 'LIMIT_EXCEEDED'
                && $e->orderId === null
                && $e->quantityMg === 100_000,
        );
    }

    #[Test]
    public function an_order_below_the_instrument_minimum_is_refused(): void
    {
        $this->expectException(InvalidOrderException::class);
        $this->expectExceptionMessage('below the instrument minimum');

        // GOLD-995-T0 requires 50 g.
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 10_000, self::PRICE);
    }

    #[Test]
    public function an_order_that_is_not_a_lot_multiple_is_refused(): void
    {
        $this->expectException(InvalidOrderException::class);
        $this->expectExceptionMessage('not a multiple of lot size');

        // 100,500 mg against a 1,000 mg lot size.
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_500, self::PRICE);
    }

    #[Test]
    public function a_price_off_the_tick_grid_is_refused(): void
    {
        $this->expectException(InvalidOrderException::class);
        $this->expectExceptionMessage('not a multiple of tick size');

        // 10,000 rial tick size.
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE + 1);
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function an_order_larger_than_the_balance_is_refused_and_leaves_nothing_behind(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        try {
            // 2 kg wanted against a 1 kg balance.
            $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 2_000_000, self::PRICE);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(1_000_000, $this->goldBalance(self::SELLER_ORG));
            $this->assertSame(0, $this->goldBalance(self::SELLER_ORG, Bucket::RESERVED));
            $this->assertSystemConserved();
        }
    }
}
