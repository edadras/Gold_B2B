<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Application\PriceSourceFallback;
use App\Modules\Pricing\Database\Factories\PriceSourceFactory;
use App\Modules\Pricing\Domain\PriceSourceMode;
use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Domain\SourceStatus;
use App\Modules\Pricing\Events\NoPriceAvailable;
use App\Modules\Pricing\Events\PriceSourceDegraded;
use App\Modules\Pricing\Infrastructure\Models\PriceSource;
use App\Modules\Pricing\Infrastructure\Models\PriceTick;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The fallback ladder of docs/03-domain/07-pricing.md §7.4. */
final class PriceSourceFallbackTest extends TestCase
{
    use RefreshDatabase;

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
    public function a_healthy_primary_is_used(): void
    {
        $primary = $this->source('ounce_primary', 1);
        $this->acceptedTick($primary, 2_650_400_000, CarbonImmutable::now()->subSeconds(5));

        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame(PriceSourceMode::PRIMARY, $resolution->mode);
        $this->assertSame(2_650_400_000, $resolution->value);
        $this->assertSame('ounce_primary', $resolution->sourceCode);
        $this->assertFalse($resolution->shouldHaltMarket);
    }

    #[Test]
    public function a_stale_primary_falls_through_and_is_marked_degraded(): void
    {
        Event::fake([PriceSourceDegraded::class]);

        $primary = $this->source('ounce_primary', 1, ['max_staleness_s' => 300]);
        $secondary = $this->source('ounce_secondary', 2);

        $this->acceptedTick($primary, 2_600_000_000, CarbonImmutable::now()->subSeconds(1_800));
        $this->acceptedTick($secondary, 2_650_400_000, CarbonImmutable::now()->subSeconds(5));

        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame(PriceSourceMode::FALLBACK, $resolution->mode);
        $this->assertSame('ounce_secondary', $resolution->sourceCode);
        $this->assertSame(SourceStatus::DEGRADED, $primary->refresh()->status);
        Event::assertDispatched(PriceSourceDegraded::class);
    }

    #[Test]
    public function a_source_marked_down_is_skipped_even_with_a_fresh_tick(): void
    {
        $primary = $this->source('ounce_primary', 1, ['status' => SourceStatus::DOWN->value]);
        $secondary = $this->source('ounce_secondary', 2);

        $this->acceptedTick($primary, 2_600_000_000, CarbonImmutable::now());
        $this->acceptedTick($secondary, 2_650_400_000, CarbonImmutable::now());

        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame('ounce_secondary', $resolution->sourceCode);
    }

    #[Test]
    public function manual_mode_takes_over_when_no_automatic_source_is_healthy(): void
    {
        $primary = $this->source('ounce_primary', 1);
        $this->acceptedTick($primary, 2_600_000_000, CarbonImmutable::now()->subSeconds(1_800));

        $manual = PriceSourceFactory::new()
            ->ofType(PriceType::OUNCE_USD)
            ->manual()
            ->create(['code' => 'ounce_manual']);
        $this->acceptedTick($manual, 2_700_000_000, CarbonImmutable::now()->subSeconds(10));

        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame(PriceSourceMode::MANUAL, $resolution->mode);
        $this->assertSame(2_700_000_000, $resolution->value);
        $this->assertTrue($resolution->requiresBanner());
    }

    #[Test]
    public function a_gap_longer_than_five_minutes_signals_that_the_market_must_halt(): void
    {
        Event::fake([NoPriceAvailable::class]);

        $primary = $this->source('ounce_primary', 1);
        $this->acceptedTick($primary, 2_650_400_000, CarbonImmutable::now()->subSeconds(600));

        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame(PriceSourceMode::NONE, $resolution->mode);
        $this->assertTrue($resolution->shouldHaltMarket);
        $this->assertSame(600, $resolution->secondsSinceLastPrice);

        Event::assertDispatched(
            NoPriceAvailable::class,
            fn (NoPriceAvailable $event): bool => $event->shouldHaltMarket
                && $event->priceType === PriceType::OUNCE_USD->value,
        );
    }

    #[Test]
    public function a_short_gap_does_not_demand_a_halt_yet(): void
    {
        Event::fake([NoPriceAvailable::class]);

        // 120 s is beyond the source's own 60 s staleness window but inside the
        // 300 s no-price limit, so we have no price yet no reason to halt.
        $primary = $this->source('ounce_primary', 1, ['max_staleness_s' => 60]);
        $this->acceptedTick($primary, 2_650_400_000, CarbonImmutable::now()->subSeconds(120));

        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame(PriceSourceMode::NONE, $resolution->mode);
        $this->assertFalse($resolution->shouldHaltMarket);
    }

    #[Test]
    public function no_source_at_all_demands_a_halt(): void
    {
        $resolution = $this->fallback()->resolve(PriceType::OUNCE_USD);

        $this->assertSame(PriceSourceMode::NONE, $resolution->mode);
        $this->assertTrue($resolution->shouldHaltMarket);
        $this->assertNull($resolution->secondsSinceLastPrice);
    }

    private function fallback(): PriceSourceFallback
    {
        return $this->app->make(PriceSourceFallback::class);
    }

    /** @param array<string, mixed> $attributes */
    private function source(string $code, int $priority, array $attributes = []): PriceSource
    {
        return PriceSourceFactory::new()
            ->ofType(PriceType::OUNCE_USD)
            ->priority($priority)
            ->create(['code' => $code] + $attributes);
    }

    private function acceptedTick(PriceSource $source, int $value, CarbonImmutable $observedAt): PriceTick
    {
        return PriceTick::query()->create([
            'source_id' => $source->id,
            'price_type' => $source->price_type->value,
            'value' => $value,
            'scale' => $source->price_type->defaultScale(),
            'observed_at' => $observedAt,
            'received_at' => $observedAt,
            'is_accepted' => true,
        ]);
    }
}
