<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests\Feature;

use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use App\Modules\Pricing\Contracts\TradeSource as PricingTradeSource;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Infrastructure\Readers\EloquentTradeHistoryReader;
use App\Modules\Trading\Infrastructure\Readers\EloquentTradePrintSource;
use App\Modules\Trading\Infrastructure\Readers\EloquentTradingExposureReader;
use App\Modules\Trading\Tests\TradingTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three interfaces other modules declared and left stubbed.
 *
 * Risk and Pricing each shipped a null implementation so they could run before
 * Trading existed; TradingServiceProvider replaces them. These tests assert
 * both halves of that: the container really does resolve to Trading's classes,
 * and the data they return is what the interfaces promise.
 */
final class ContractReadersTest extends TradingTestCase
{
    use RefreshDatabase;

    private const PRICE = 78_480_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowAllRisk();
        $this->setUpMarket(self::SELLER_ORG, self::BUYER_ORG);

        $this->depositGold(self::SELLER_ORG, 5_000_000);
        $this->depositRial(self::BUYER_ORG, 500_000_000_000);
    }

    #[Test]
    public function the_provider_replaces_every_stubbed_reader(): void
    {
        $this->assertInstanceOf(
            EloquentTradeHistoryReader::class,
            $this->app->make(TradeHistoryReaderInterface::class),
        );

        $this->assertInstanceOf(
            EloquentTradingExposureReader::class,
            $this->app->make(TradingExposureReaderInterface::class),
        );

        $this->assertInstanceOf(
            EloquentTradePrintSource::class,
            $this->app->make(TradePrintSourceInterface::class),
        );
    }

    #[Test]
    public function the_history_reader_sees_a_trade_from_both_sides(): void
    {
        $this->trade(100_000);

        $reader = $this->app->make(TradeHistoryReaderInterface::class);
        $since = CarbonImmutable::now()->subHour();

        $sellerView = $reader->tradesFor(self::SELLER_ORG, $since);
        $buyerView = $reader->tradesFor(self::BUYER_ORG, $since);

        $this->assertCount(1, $sellerView);
        $this->assertCount(1, $buyerView);
        $this->assertSame($sellerView[0]->id, $buyerView[0]->id);

        $this->assertSame(100_000, $sellerView[0]->fineWeightMg);
        $this->assertSame(self::PRICE, $sellerView[0]->pricePerGramRial);
        $this->assertSame('ORDER_BOOK', $sellerView[0]->source);

        // The counterparty helper the AML rules lean on.
        $this->assertSame(self::BUYER_ORG, $sellerView[0]->counterpartyOf(self::SELLER_ORG));

        $this->assertCount(1, $reader->tradesBetween(self::SELLER_ORG, self::BUYER_ORG, $since));
        $this->assertCount(0, $reader->tradesBetween(self::SELLER_ORG, 999, $since));
        $this->assertCount(1, $reader->recentTrades($since));

        $this->assertSame(100_000, $reader->dailyVolumeMg(self::SELLER_ORG, CarbonImmutable::now()));
        // Today is excluded from the baseline, so it is still zero.
        $this->assertSame(0, $reader->averageDailyVolumeMg(self::SELLER_ORG, 30));
    }

    #[Test]
    public function the_exposure_reader_counts_open_orders_and_unsettled_trades(): void
    {
        // One resting sell (reserved) and one executed trade (unsettled).
        $this->trade(100_000);
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, 200_000, self::PRICE + 50_000);

        $reader = $this->app->make(TradingExposureReaderInterface::class);

        // 100 g promised by the executed trade + 200 g still locked by the
        // resting order.
        $this->assertSame(300_000, $reader->openGoldExposureMg(self::SELLER_ORG));
        $this->assertSame(1, $reader->openOrderCount(self::SELLER_ORG));

        // The buyer owes the trade's net and has nothing resting.
        $this->assertGreaterThan(0, $reader->openRialExposure(self::BUYER_ORG));
        $this->assertSame(0, $reader->openOrderCount(self::BUYER_ORG));

        $this->assertSame(
            100_000,
            $reader->counterpartyDailyVolumeMg(self::SELLER_ORG, self::BUYER_ORG),
        );
    }

    #[Test]
    public function the_print_source_replays_trades_in_a_half_open_window(): void
    {
        $this->trade(100_000);

        $source = $this->app->make(TradePrintSourceInterface::class);
        $instrumentId = $this->instrument()->id;

        $prints = $source->printsBetween(
            $instrumentId,
            CarbonImmutable::now()->subHour(),
            CarbonImmutable::now()->addHour(),
        );

        $this->assertCount(1, $prints);
        $this->assertSame(self::PRICE, $prints[0]->pricePerFineGramRial);
        $this->assertSame(100_000, $prints[0]->fineWeightMg);
        $this->assertSame(PricingTradeSource::ORDER_BOOK, $prints[0]->source);
        $this->assertNotNull($prints[0]->tradeId);

        // Half-open: a window ending at the execution instant excludes it.
        $this->assertCount(
            0,
            $source->printsBetween($instrumentId, CarbonImmutable::now()->subHour(), $prints[0]->executedAt),
        );

        $this->assertContains($instrumentId, $source->activeInstrumentIds());
    }

    private function trade(int $quantityMg): void
    {
        $this->placeLimit(self::SELLER_ORG, self::SELLER_USER, Side::SELL, $quantityMg, self::PRICE);
        $this->placeLimit(self::BUYER_ORG, self::BUYER_USER, Side::BUY, $quantityMg, self::PRICE);
    }
}
