<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Application\QuoteService;
use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradeSource;
use App\Modules\Pricing\Domain\Spread;
use App\Modules\Pricing\Domain\VwapCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/03-domain/07-pricing.md §7.6 and F22 / F23. */
final class QuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private const INSTRUMENT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-01-05 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function vwap_matches_the_worked_example_and_includes_otc(): void
    {
        $service = $this->service();

        // The F22 worked example, with the middle print executed OTC.
        $service->recordTrade($this->print(78_480_000, 100_000, TradeSource::ORDER_BOOK, 0));
        $service->recordTrade($this->print(78_500_000, 250_000, TradeSource::OTC, 60));
        $service->recordTrade($this->print(78_420_000, 50_000, TradeSource::ORDER_BOOK, 120));

        $snapshot = $service->snapshot(self::INSTRUMENT);

        $this->assertNotNull($snapshot);
        $this->assertSame(78_485_000, $snapshot->dayVwap);
        $this->assertSame(400_000, $snapshot->dayVolumeMg);
        $this->assertSame(3, $snapshot->dayTradeCount);
    }

    #[Test]
    public function the_pure_calculator_agrees_with_the_worked_example(): void
    {
        $vwap = (new VwapCalculator)->vwap([
            [78_480_000, 100_000],
            [78_500_000, 250_000],
            [78_420_000, 50_000],
        ]);

        $this->assertSame(78_485_000, $vwap);
    }

    #[Test]
    public function otc_prints_never_move_the_last_price(): void
    {
        $service = $this->service();

        $service->recordTrade($this->print(78_480_000, 100_000, TradeSource::ORDER_BOOK, 0));
        $service->recordTrade($this->print(90_000_000, 250_000, TradeSource::OTC, 60));

        $snapshot = $service->snapshot(self::INSTRUMENT);

        $this->assertNotNull($snapshot);
        $this->assertSame(78_480_000, $snapshot->lastPrice, 'a fabricated OTC print must not steer LAST');
        // …but it does count towards the session high and the volume.
        $this->assertSame(90_000_000, $snapshot->dayHigh);
        $this->assertSame(350_000, $snapshot->dayVolumeMg);
    }

    #[Test]
    public function open_high_low_track_the_session(): void
    {
        $service = $this->service();

        $service->recordTrade($this->print(78_120_000, 10_000, TradeSource::ORDER_BOOK, 0));
        $service->recordTrade($this->print(78_610_000, 10_000, TradeSource::ORDER_BOOK, 30));
        $service->recordTrade($this->print(78_050_000, 10_000, TradeSource::ORDER_BOOK, 60));

        $snapshot = $service->snapshot(self::INSTRUMENT);

        $this->assertNotNull($snapshot);
        $this->assertSame(78_120_000, $snapshot->dayOpen);
        $this->assertSame(78_610_000, $snapshot->dayHigh);
        $this->assertSame(78_050_000, $snapshot->dayLow);
        $this->assertSame(78_050_000, $snapshot->lastPrice);
        $this->assertSame(-70_000, $snapshot->changeRial());
    }

    #[Test]
    public function a_new_session_resets_the_day_but_carries_last_over_as_stale(): void
    {
        $service = $this->service();

        $service->recordTrade($this->print(78_120_000, 10_000, TradeSource::ORDER_BOOK, 0));

        CarbonImmutable::setTestNow('2026-01-06 10:00:00');
        $service->recordTrade($this->print(79_000_000, 20_000, TradeSource::OTC, 0));

        $snapshot = $service->snapshot(self::INSTRUMENT);

        $this->assertNotNull($snapshot);
        $this->assertSame(20_000, $snapshot->dayVolumeMg, 'volume restarts with the session');
        $this->assertSame(79_000_000, $snapshot->dayOpen);
        $this->assertSame(78_120_000, $snapshot->lastPrice, "yesterday's LAST survives");
        $this->assertTrue($snapshot->lastIsStale);
    }

    #[Test]
    public function top_of_book_and_spread(): void
    {
        $service = $this->service();

        $service->updateTopOfBook(self::INSTRUMENT, 78_420_000, 300_000, 78_480_000, 350_000);

        $snapshot = $service->snapshot(self::INSTRUMENT);

        $this->assertNotNull($snapshot);
        $this->assertSame(78_420_000, $snapshot->bestBid);
        $this->assertSame(78_480_000, $snapshot->bestAsk);
        $this->assertNotNull($snapshot->spread);
        $this->assertSame(60_000, $snapshot->spread->rial);
        $this->assertSame(78_450_000, $snapshot->spread->mid);
        // floor(60,000 × 10,000 / 78,450,000) = 7 bps ≈ 0.08%
        $this->assertSame(7, $snapshot->spread->bps);
    }

    #[Test]
    public function the_spread_helper_matches_f23(): void
    {
        $spread = Spread::between(78_420_000, 78_480_000);

        $this->assertSame(60_000, $spread->rial);
        $this->assertSame(78_450_000, $spread->mid);
        $this->assertSame(7, $spread->bps);
        $this->assertFalse($spread->isCrossed());
        $this->assertTrue(Spread::between(78_480_000, 78_420_000)->isCrossed());
    }

    private function service(): QuoteService
    {
        return $this->app->make(QuoteService::class);
    }

    private function print(int $price, int $fineMg, TradeSource $source, int $offsetSeconds): TradePrint
    {
        return TradePrint::make(
            self::INSTRUMENT,
            $price,
            $fineMg,
            CarbonImmutable::now()->addSeconds($offsetSeconds),
            $source,
        );
    }
}
