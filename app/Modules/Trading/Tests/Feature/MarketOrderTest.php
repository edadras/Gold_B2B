<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Trading\Application\Commands\PlaceOrderCommand;
use App\Modules\Trading\Domain\Exceptions\InvalidOrderException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Tests\TradingTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * MARKET orders and the reservation problem they create (§4.3, F10).
 *
 * A market order names no price, so the platform cannot know what to lock. The
 * answer in the document is to reserve against the worst price the order would
 * accept — best_ask x (1 + slippage) — and hand back whatever the fill does not
 * need. The same band then stops the sweep: past it the order stops filling
 * rather than pay a price the member never agreed to.
 */
final class MarketOrderTest extends TradingTestCase
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

    #[Test]
    #[Group('ledger-invariants')]
    public function a_market_buy_reserves_at_the_worst_acceptable_price_and_releases_the_rest(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);

        $result = $this->placeMarket(Side::BUY, 100_000, 50);

        $this->assertCount(1, $result->trades);
        $this->assertSame(self::PRICE, $result->trades[0]->price_per_gram_rial);

        $order = Order::query()->findOrFail($result->order->id);
        $this->assertSame(OrderStatus::FILLED, $order->status);

        // Reserved against 78,480,000 x 1.005 = 78,872,400 (F10's own example),
        // executed at 78,480,000, so the difference came back.
        $this->assertSame(78_872_400, $order->metadata['worst_price_rial']);
        $this->assertGreaterThan($order->consumed_amount, $order->reserved_amount);
        $this->assertSame(
            $order->reserved_amount - $order->consumed_amount,
            $order->released_amount,
        );

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function a_market_order_stops_sweeping_when_the_price_leaves_its_slippage_band(): void
    {
        // 100 g at the touch, then 100 g far above the 0.5% band.
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, self::PRICE);
        $this->placeLimit(self::THIRD_ORG, 900, Side::SELL, 100_000, self::PRICE + 5_000_000);

        $result = $this->placeMarket(Side::BUY, 200_000, 50);

        // Only the level inside the band traded.
        $this->assertCount(1, $result->trades);
        $this->assertSame(100_000, $result->trades[0]->quantity_fine_mg);

        $order = Order::query()->findOrFail($result->order->id);
        $this->assertSame(100_000, $order->filled_mg);
        $this->assertSame(OrderStatus::PARTIALLY_FILLED, $order->status);
    }

    #[Test]
    public function a_market_order_is_refused_when_there_is_nothing_to_price_it_against(): void
    {
        // Empty book and a price reader with nothing to say.
        $this->expectException(InvalidOrderException::class);
        $this->expectExceptionMessage('needs a resting opposite side');

        $this->placeMarket(Side::BUY, 100_000, 50);
    }

    #[Test]
    public function a_market_order_falls_back_to_the_pricing_module_when_the_book_is_empty(): void
    {
        // No resting sell, but Pricing knows a last price — enough to size the
        // reservation, even though nothing will match.
        $this->fixPrice(self::PRICE);

        $result = $this->placeMarket(Side::BUY, 100_000, 50);

        $this->assertSame([], $result->trades);
        $this->assertGreaterThan(0, $result->order->reserved_amount);
        $this->assertGreaterThan(0, $this->rialBalance(self::BUYER_ORG, Bucket::RESERVED));
    }

    private function placeMarket(Side $side, int $quantityMg, int $slippageBps): \App\Modules\Trading\Application\Results\OrderResult
    {
        return $this->orders()->place(new PlaceOrderCommand(
            organizationId: self::BUYER_ORG,
            userId: self::BUYER_USER,
            representativeId: null,
            instrumentCode: self::INSTRUMENT,
            side: $side,
            type: OrderType::MARKET,
            timeInForce: TimeInForce::DAY,
            quantity: FineWeight::fromMilligrams($quantityMg),
            price: null,
            maxSlippageBps: $slippageBps,
        ));
    }
}
