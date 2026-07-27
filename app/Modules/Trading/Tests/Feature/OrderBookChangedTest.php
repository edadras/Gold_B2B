<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Trading\Application\CancelOrderService;
use App\Modules\Trading\Application\OrderBookReader;
use App\Modules\Trading\Contracts\OrderBookLevel;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Events\OrderBookChanged;
use App\Modules\Trading\Tests\TradingTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * The book-level event, and the fact that it says what the depth endpoint says.
 *
 * Order events report an order. This one reports the ladder, and it is the only
 * producer of `depth.updated` on the public market channel — a market data
 * consumer that never sees it sees a frozen book.
 */
final class OrderBookChangedTest extends TradingTestCase
{
    use RefreshDatabase;

    /** @var array<int, OrderBookChanged> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);
        $this->depositGold(self::SELLER_ORG, 1_000_000);
        $this->depositRial(self::BUYER_ORG, 500_000_000_000);

        // Collected, not faked: Event::fake would suppress the very listener
        // that produces the event under test.
        Event::listen(OrderBookChanged::class, function (OrderBookChanged $event): void {
            $this->captured[] = $event;
        });
    }

    #[Test]
    public function placing_an_order_publishes_the_new_ladder(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, 78_480_000);

        $this->assertCount(1, $this->captured, 'A resting order must change the published book');

        $event = $this->captured[0];

        $this->assertSame(self::INSTRUMENT, $event->instrumentCode);
        $this->assertSame([], $event->bids);
        $this->assertSame([[78_480_000, 100_000, 1]], $event->asks);
    }

    #[Test]
    public function levels_aggregate_quantity_and_count_but_never_identity(): void
    {
        $this->depositGold(self::BUYER_ORG, 1_000_000);

        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, 78_480_000);
        $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::SELL, 60_000, 78_480_000);

        $event = $this->latest();

        $this->assertSame([[78_480_000, 160_000, 2]], $event->asks);

        // Two different organisations rest at that price and the frame carries
        // neither of them. Encoding the whole event is the check that survives
        // a future field being added to it.
        $payload = json_encode(get_object_vars($event), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString((string) self::SELLER_ORG, $payload);
        $this->assertStringNotContainsString((string) self::BUYER_ORG, $payload);
    }

    #[Test]
    public function a_crossing_placement_publishes_once_not_once_per_order_event(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, 78_480_000);

        $this->captured = [];

        // Fires OrderPlaced and a fill event for each side. The ladder is
        // identical by the time each of them is handled, so one frame goes out.
        $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, 100_000, 78_480_000);

        $this->assertCount(1, $this->captured, 'One book change, not one per order event');

        $this->assertSame([], $this->captured[0]->bids);
        $this->assertSame([], $this->captured[0]->asks, 'The book is empty once both sides fill');
    }

    #[Test]
    public function cancelling_removes_the_level(): void
    {
        $result = $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, 78_480_000);

        $this->captured = [];

        $this->app->make(CancelOrderService::class)
            ->cancel($result->order->id, self::SELLER_ORG, self::SELLER_USER);

        $this->assertCount(1, $this->captured);
        $this->assertSame([], $this->captured[0]->asks);
    }

    #[Test]
    public function the_published_ladder_matches_the_depth_endpoint(): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 100_000, 78_480_000);
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 60_000, 78_500_000);

        $snapshot = $this->app->make(OrderBookReader::class)->depth($this->instrument());

        $asks = array_map(
            static fn (OrderBookLevel $level): array => [
                $level->priceRial,
                $level->quantityMg,
                $level->orderCount,
            ],
            $snapshot->asks,
        );

        $this->assertSame($asks, $this->latest()->asks, 'Broadcast depth and API depth must not disagree');
    }

    private function latest(): OrderBookChanged
    {
        $this->assertNotEmpty($this->captured, 'No OrderBookChanged was published');

        return $this->captured[array_key_last($this->captured)];
    }
}
