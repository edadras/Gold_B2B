<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Contracts\TradePrint;
use App\Modules\Pricing\Contracts\TradePrintSourceInterface;
use App\Modules\Pricing\Contracts\TradeSource;
use App\Modules\Pricing\Database\Factories\PriceSourceFactory;
use App\Modules\Pricing\Domain\CandleInterval;
use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Infrastructure\Drivers\ManualPriceDriver;
use App\Modules\Pricing\Infrastructure\Drivers\StubPriceDriver;
use App\Modules\Pricing\Infrastructure\InMemoryTradePrintSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The two artisan commands of docs/03-domain/07-pricing.md §7.2 and §7.7. */
final class PricingConsoleTest extends TestCase
{
    use RefreshDatabase;

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
    public function fetch_reference_polls_the_stub_driver_and_stores_a_reference_price(): void
    {
        PriceSourceFactory::new()->ofType(PriceType::OUNCE_USD)->create([
            'code' => 'ounce_stub',
            'driver' => StubPriceDriver::CODE,
            'priority' => 1,
        ]);
        PriceSourceFactory::new()->ofType(PriceType::USD_IRR)->create([
            'code' => 'fx_stub',
            'driver' => StubPriceDriver::CODE,
            'priority' => 1,
        ]);

        $this->app->make(StubPriceDriver::class)
            ->set(PriceType::OUNCE_USD, 2_650_400_000)
            ->set(PriceType::USD_IRR, 620_000);

        $this->artisan('pricing:fetch-reference', ['--instrument' => 3])->assertSuccessful();

        $this->assertDatabaseHas('reference_prices', [
            'instrument_id' => 3,
            'fine_gram_rial' => 52_831_649,
            'ounce_usd_micro' => 2_650_400_000,
            'usd_irr' => 620_000,
            'mode' => 'PRIMARY',
        ]);
        $this->assertDatabaseCount('price_ticks', 2);
    }

    #[Test]
    public function fetch_reference_fails_loudly_when_a_leg_is_missing(): void
    {
        PriceSourceFactory::new()->ofType(PriceType::OUNCE_USD)->create([
            'code' => 'ounce_stub',
            'driver' => StubPriceDriver::CODE,
        ]);

        $this->app->make(StubPriceDriver::class)->set(PriceType::OUNCE_USD, 2_650_400_000);

        $this->artisan('pricing:fetch-reference')->assertFailed();
    }

    #[Test]
    public function fetch_reference_is_a_no_op_without_configured_sources(): void
    {
        $this->artisan('pricing:fetch-reference')->assertSuccessful();

        $this->assertDatabaseCount('price_ticks', 0);
    }

    #[Test]
    public function the_manual_driver_serves_an_operator_entered_price(): void
    {
        $driver = $this->app->make(ManualPriceDriver::class);
        $driver->clear(PriceType::USD_IRR);

        $this->assertNull($driver->fetch(PriceType::USD_IRR));

        $driver->submit(PriceType::USD_IRR, 640_000);

        $tick = $driver->fetch(PriceType::USD_IRR);
        $this->assertNotNull($tick);
        $this->assertSame(640_000, $tick->value);

        $driver->clear(PriceType::USD_IRR);
    }

    #[Test]
    public function build_ohlc_writes_candles_for_every_interval(): void
    {
        $bucketStart = CandleInterval::M1->bucketStart(CarbonImmutable::now());

        $this->app->instance(TradePrintSourceInterface::class, new InMemoryTradePrintSource([
            TradePrint::make(4, 78_120_000, 100_000, $bucketStart->addSeconds(5), TradeSource::ORDER_BOOK),
        ]));

        $this->artisan('pricing:build-ohlc')->assertSuccessful();

        $this->assertDatabaseCount('price_candles', count(CandleInterval::all()));
        $this->assertDatabaseHas('price_candles', [
            'instrument_id' => 4,
            'interval_code' => '5m',
            'open_price' => 78_120_000,
            'volume_mg' => 100_000,
        ]);
    }

    #[Test]
    public function build_ohlc_can_be_narrowed_to_one_instrument_and_interval(): void
    {
        $bucketStart = CandleInterval::M15->bucketStart(CarbonImmutable::now());

        $this->app->instance(TradePrintSourceInterface::class, new InMemoryTradePrintSource([
            TradePrint::make(4, 78_120_000, 100_000, $bucketStart->addSeconds(5), TradeSource::ORDER_BOOK),
        ]));

        $this->artisan('pricing:build-ohlc', ['--instrument' => 4, '--interval' => '15m'])
            ->assertSuccessful();

        $this->assertDatabaseCount('price_candles', 1);
    }

    #[Test]
    public function build_ohlc_writes_nothing_when_no_trades_exist(): void
    {
        $this->artisan('pricing:build-ohlc')->assertSuccessful();

        $this->assertDatabaseCount('price_candles', 0);
    }
}
