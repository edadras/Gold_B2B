<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Trading\Domain\MarketSessionStatus;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Events\MarketSessionOpened;
use App\Modules\Trading\Events\OpeningAuctionCompleted;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\MarketSession;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\Trade;
use App\Modules\Trading\Tests\TradingTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The batch auction of docs/03-domain/04-trading.md §4.8.
 *
 * Every test here builds a book while the session is PRE_OPEN — where orders
 * are accepted and nothing matches — and then opens the market. What the
 * auction must do that a continuous sweep cannot is the subject of
 * every_fill_prints_the_same_price: the same book swept continuously would
 * print three different prices.
 */
final class OpeningAuctionTest extends TradingTestCase
{
    use RefreshDatabase;

    private const THIRD_ORG = 355;

    private const THIRD_USER = 900;

    private const GRAM = 1_000;

    private Instrument $instrument;

    /** @var list<OpeningAuctionCompleted> */
    private array $auctions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->instrument = $this->setUpPreOpenMarket(self::SELLER_ORG, self::BUYER_ORG, self::THIRD_ORG);

        foreach ([self::SELLER_ORG, self::BUYER_ORG, self::THIRD_ORG] as $org) {
            $this->depositGold($org, 10_000_000);
            $this->depositRial($org, 5_000_000_000_000);
        }

        // Captured by listening, not by Event::fake(): the trades have to be
        // really written for the rest of the assertions to mean anything.
        $this->auctions = [];

        Event::listen(
            OpeningAuctionCompleted::class,
            function (OpeningAuctionCompleted $event): void {
                $this->auctions[] = $event;
            },
        );
    }

    // ── the clearing price ───────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function it_clears_at_the_price_that_maximises_executable_volume(): void
    {
        // 78,400,000 crosses 100 g, 78,600,000 crosses 100 g, 78,500,000
        // crosses 200 g. There is no tie to break.
        $this->buy(self::BUYER_ORG, 100, 78_600_000);
        $this->buy(self::BUYER_ORG, 100, 78_500_000);
        $this->sell(self::SELLER_ORG, 100, 78_400_000);
        $this->sell(self::SELLER_ORG, 100, 78_500_000);

        $this->openMarket();

        $trades = $this->trades();

        self::assertCount(2, $trades);
        self::assertSame(200 * self::GRAM, array_sum(array_map(
            static fn (Trade $t): int => $t->quantity_fine_mg,
            $trades,
        )));

        foreach ($trades as $trade) {
            self::assertSame(78_500_000, $trade->price_per_gram_rial);
        }

        // The buyer who bid 78,600,000 pays the clearing price, not its own.
        self::assertSame(78_500_000, $this->marketSession()->opening_price_rial);

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    #[Test]
    public function a_volume_tie_is_broken_by_the_smaller_residual_imbalance(): void
    {
        // 78,500,000 → demand 150 g, supply 100 g: 100 g crosses, 50 g left over.
        // 78,600,000 → demand 100 g, supply 100 g: 100 g crosses, nothing left.
        // Equal volume; the second price leaves no imbalance, so it wins even
        // though tie-break (c) would otherwise take the lower of the two.
        $this->buy(self::BUYER_ORG, 100, 78_600_000);
        $this->buy(self::BUYER_ORG, 50, 78_500_000);
        $this->sell(self::SELLER_ORG, 100, 78_500_000);

        $this->openMarket();

        $trades = $this->trades();

        self::assertCount(1, $trades);
        self::assertSame(78_600_000, $trades[0]->price_per_gram_rial);
        self::assertSame(100 * self::GRAM, $trades[0]->quantity_fine_mg);
    }

    #[Test]
    public function a_volume_and_imbalance_tie_is_broken_by_the_reference_price(): void
    {
        // One buy at 78,600,000 against one sell at 78,400,000. Both prices
        // cross exactly 100 g and leave zero imbalance, so the book alone
        // cannot choose. Left to tie-break (c) the answer would be the lower
        // price, 78,400,000; the reference price pulls it to 78,600,000.
        $this->fixPrice(78_600_000);

        $this->buy(self::BUYER_ORG, 100, 78_600_000);
        $this->sell(self::SELLER_ORG, 100, 78_400_000);

        $this->openMarket();

        $trades = $this->trades();

        self::assertCount(1, $trades);
        self::assertSame(78_600_000, $trades[0]->price_per_gram_rial);
    }

    // ── one auction, one price ───────────────────────────────────────────────

    #[Test]
    public function every_fill_prints_the_same_price(): void
    {
        // Three offers at three prices meet one 300 g bid. Continuous matching
        // would print 78,300,000, then 78,400,000, then 78,500,000 — three
        // prices for what §4.8 calls one auction. The batch prints one.
        $this->sell(self::SELLER_ORG, 100, 78_300_000);
        $this->sell(self::THIRD_ORG, 100, 78_400_000, self::THIRD_USER);
        $this->sell(self::SELLER_ORG, 100, 78_500_000);
        $this->buy(self::BUYER_ORG, 300, 78_500_000);

        $this->openMarket();

        $trades = $this->trades();

        self::assertCount(3, $trades);
        self::assertSame(
            [78_500_000],
            array_values(array_unique(array_map(
                static fn (Trade $t): int => $t->price_per_gram_rial,
                $trades,
            ))),
        );

        $event = $this->auctionEvent();

        self::assertSame(78_500_000, $event->clearingPriceRial);
        self::assertSame(300 * self::GRAM, $event->matchedVolumeMg);
        self::assertSame(4, $event->orderCount);
        self::assertSame(3, $event->tradeCount);
        self::assertFalse($event->resumedFromPause);
    }

    // ── the margin ───────────────────────────────────────────────────────────

    #[Test]
    #[Group('ledger-invariants')]
    public function a_marginal_order_is_partially_filled_and_its_remainder_stays_in_the_book(): void
    {
        $buy = $this->buy(self::BUYER_ORG, 150, 78_500_000);
        $this->sell(self::SELLER_ORG, 100, 78_500_000);

        $this->openMarket();

        $buy = Order::query()->findOrFail($buy->id);

        self::assertSame(OrderStatus::PARTIALLY_FILLED, $buy->status);
        self::assertSame(100 * self::GRAM, $buy->filled_mg);
        self::assertSame(50 * self::GRAM, $buy->remainingMg());

        // Still matchable, so continuous trading picks it up where the auction
        // left it — and the reservation for the unfilled half is still held.
        self::assertGreaterThan(0, $buy->outstandingReservation());

        $this->assertEveryLedgerGroupBalances();
        $this->assertSystemConserved();
    }

    // ── degenerate books ─────────────────────────────────────────────────────

    #[Test]
    public function an_empty_book_opens_cleanly_with_no_trades(): void
    {
        $this->openMarket();

        self::assertSame(0, Trade::query()->count());
        self::assertNull($this->marketSession()->opening_price_rial);

        // Nothing crossed, so there is no clearing price to announce.
        self::assertSame([], $this->auctions);
    }

    #[Test]
    public function a_one_sided_book_opens_cleanly_with_no_trades(): void
    {
        $this->sell(self::SELLER_ORG, 100, 78_500_000);
        $this->sell(self::THIRD_ORG, 100, 78_400_000, self::THIRD_USER);

        $this->openMarket();

        self::assertSame(0, Trade::query()->count());
    }

    #[Test]
    public function resuming_from_a_circuit_breaker_pause_runs_the_auction_again(): void
    {
        $this->openMarket();
        $this->sessions()->pauseForCircuitBreaker($this->instrument->id, 384, 300);

        $opened = null;

        Event::listen(
            MarketSessionOpened::class,
            static function (MarketSessionOpened $e) use (&$opened): void {
                $opened = $e;
            },
        );

        $session = $this->sessions()->open($this->instrument);

        self::assertSame(MarketSessionStatus::OPEN, $session->status);
        self::assertTrue($opened?->resumedFromPause);

        // §4.8 forbids new orders during a pause, so the book that survives one
        // is the uncrossed remainder of continuous trading. The re-opening
        // auction still runs over it — it simply has nothing to cross here.
        self::assertSame(0, Trade::query()->count());
    }

    #[Test]
    public function self_trades_are_excluded_from_the_auction(): void
    {
        // The buyer's own offer is the first in the sell queue and the whole
        // reason the book looks 200 g deep. It must never fill against the
        // buyer, so only the genuine counterparty's 100 g trades.
        $buy = $this->buy(self::BUYER_ORG, 200, 78_500_000);
        $ownSell = $this->sell(self::BUYER_ORG, 100, 78_500_000, self::BUYER_USER);
        $this->sell(self::SELLER_ORG, 100, 78_500_000);

        $this->openMarket();

        $trades = $this->trades();

        self::assertCount(1, $trades);
        self::assertSame(self::BUYER_ORG, $trades[0]->buyer_organization_id);
        self::assertSame(self::SELLER_ORG, $trades[0]->seller_organization_id);

        self::assertSame(0, Order::query()->findOrFail($ownSell->id)->filled_mg);
        self::assertSame(100 * self::GRAM, Order::query()->findOrFail($buy->id)->filled_mg);

        foreach (Trade::query()->get() as $trade) {
            self::assertNotSame(
                $trade->buyer_organization_id,
                $trade->seller_organization_id,
                'The auction printed a wash trade',
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buy(int $org, int $grams, int $price, ?int $user = null): Order
    {
        return $this->placeLimit(
            $org,
            $user ?? self::BUYER_USER,
            Side::BUY,
            $grams * self::GRAM,
            $price,
        )->order;
    }

    private function sell(int $org, int $grams, int $price, ?int $user = null): Order
    {
        return $this->placeLimit(
            $org,
            $user ?? self::SELLER_USER,
            Side::SELL,
            $grams * self::GRAM,
            $price,
        )->order;
    }

    private function openMarket(): void
    {
        $this->sessions()->open($this->instrument);
    }

    /** @return list<Trade> */
    private function trades(): array
    {
        /** @var list<Trade> $trades */
        $trades = Trade::query()->orderBy('id')->get()->all();

        return $trades;
    }

    private function marketSession(): MarketSession
    {
        return $this->sessions()->sessionOrFail($this->instrument->id);
    }

    /** The single auction summary this test's open produced. */
    private function auctionEvent(): OpeningAuctionCompleted
    {
        self::assertCount(1, $this->auctions, 'Expected exactly one OpeningAuctionCompleted');

        return $this->auctions[0];
    }
}
