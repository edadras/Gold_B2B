<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Application\CandleService;
use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradeSource;
use App\Modules\Pricing\Domain\CandleInterval;
use App\Modules\Pricing\Infrastructure\InMemoryTradePrintSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/03-domain/07-pricing.md §7.7. */
final class CandleServiceTest extends TestCase
{
    use RefreshDatabase;

    private const INSTRUMENT = 7;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-01-05 10:07:30');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_builds_a_bucket_from_order_book_prints_only(): void
    {
        $bucketStart = CandleInterval::M5->bucketStart(CarbonImmutable::now());

        $source = new InMemoryTradePrintSource([
            $this->print(78_120_000, 100_000, TradeSource::ORDER_BOOK, $bucketStart->addSeconds(10)),
            $this->print(99_000_000, 500_000, TradeSource::OTC, $bucketStart->addSeconds(60)),
            $this->print(78_610_000, 50_000, TradeSource::ORDER_BOOK, $bucketStart->addSeconds(120)),
            $this->print(78_050_000, 25_000, TradeSource::ORDER_BOOK, $bucketStart->addSeconds(180)),
        ]);

        $candle = (new CandleService($source))->build(self::INSTRUMENT, CandleInterval::M5);

        $this->assertNotNull($candle);
        $this->assertSame(78_120_000, $candle->open_price);
        $this->assertSame(78_050_000, $candle->close_price);
        $this->assertSame(78_610_000, $candle->high_price, 'the OTC print must not set the high');
        $this->assertSame(78_050_000, $candle->low_price);
        $this->assertSame(175_000, $candle->volume_mg);
        $this->assertSame(3, $candle->trade_count);
    }

    #[Test]
    public function an_empty_bucket_produces_no_candle(): void
    {
        $candle = (new CandleService(new InMemoryTradePrintSource))
            ->build(self::INSTRUMENT, CandleInterval::M5);

        $this->assertNull($candle);
        $this->assertDatabaseCount('price_candles', 0);
    }

    #[Test]
    public function rebuilding_the_same_bucket_updates_rather_than_duplicates(): void
    {
        $bucketStart = CandleInterval::M5->bucketStart(CarbonImmutable::now());

        $source = new InMemoryTradePrintSource([
            $this->print(78_120_000, 100_000, TradeSource::ORDER_BOOK, $bucketStart->addSeconds(10)),
        ]);
        $service = new CandleService($source);

        $service->build(self::INSTRUMENT, CandleInterval::M5);
        $source->add($this->print(78_900_000, 40_000, TradeSource::ORDER_BOOK, $bucketStart->addSeconds(200)));
        $candle = $service->build(self::INSTRUMENT, CandleInterval::M5);

        $this->assertDatabaseCount('price_candles', 1);
        $this->assertNotNull($candle);
        $this->assertSame(78_900_000, $candle->close_price);
        $this->assertSame(140_000, $candle->volume_mg);
    }

    #[Test]
    public function build_all_covers_every_interval_of_every_active_instrument(): void
    {
        $bucketStart = CandleInterval::M1->bucketStart(CarbonImmutable::now());

        $source = new InMemoryTradePrintSource([
            $this->print(78_120_000, 100_000, TradeSource::ORDER_BOOK, $bucketStart->addSeconds(5)),
        ]);

        $written = (new CandleService($source))->buildAll(CarbonImmutable::now());

        $this->assertSame(count(CandleInterval::all()), $written);
        $this->assertDatabaseCount('price_candles', count(CandleInterval::all()));
    }

    #[Test]
    public function bucket_boundaries_align_to_the_interval(): void
    {
        $at = CarbonImmutable::parse('2026-01-05 10:07:30', 'Asia/Tehran');

        $this->assertSame('10:07:00', CandleInterval::M1->bucketStart($at)->format('H:i:s'));
        $this->assertSame('10:05:00', CandleInterval::M5->bucketStart($at)->format('H:i:s'));
        $this->assertSame('10:00:00', CandleInterval::M15->bucketStart($at)->format('H:i:s'));
        $this->assertSame('10:00:00', CandleInterval::H1->bucketStart($at)->format('H:i:s'));
        $this->assertSame('00:00:00', CandleInterval::D1->bucketStart($at)->format('H:i:s'));
    }

    private function print(int $price, int $fineMg, TradeSource $source, CarbonImmutable $at): TradePrint
    {
        return TradePrint::make(self::INSTRUMENT, $price, $fineMg, $at, $source);
    }
}
