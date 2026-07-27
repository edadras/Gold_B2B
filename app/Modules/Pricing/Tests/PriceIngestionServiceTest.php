<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Application\PriceIngestionService;
use App\Modules\Pricing\Contracts\IncomingTick;
use App\Modules\Pricing\Database\Factories\PriceSourceFactory;
use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Domain\RejectionReason;
use App\Modules\Pricing\Events\PriceTickAccepted;
use App\Modules\Pricing\Events\PriceTickRejected;
use App\Modules\Pricing\Infrastructure\Models\PriceSource;
use App\Modules\Pricing\Infrastructure\Models\PriceTick;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The five filters of docs/03-domain/07-pricing.md §7.4. */
final class PriceIngestionServiceTest extends TestCase
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
    public function a_clean_tick_is_accepted_and_announced(): void
    {
        Event::fake([PriceTickAccepted::class]);
        $source = $this->source();

        $result = $this->service()->ingest($this->tick($source, 2_650_400_000));

        $this->assertTrue($result->accepted);
        $this->assertNull($result->reason);
        $this->assertSame(2_650_400_000, $result->effectiveValue);
        $this->assertDatabaseHas('price_ticks', ['id' => $result->tickId, 'is_accepted' => 1]);
        Event::assertDispatched(PriceTickAccepted::class);
    }

    #[Test]
    public function filter_one_rejects_a_stale_tick(): void
    {
        Event::fake([PriceTickRejected::class]);
        $source = $this->source(['max_staleness_s' => 300]);

        $result = $this->service()->ingest($this->tick(
            $source,
            2_650_400_000,
            observedAt: CarbonImmutable::now()->subSeconds(600),
        ));

        $this->assertFalse($result->accepted);
        $this->assertSame(RejectionReason::STALE, $result->reason);
        Event::assertDispatched(PriceTickRejected::class);
    }

    #[Test]
    public function filter_two_rejects_a_jump_from_the_last_accepted_value(): void
    {
        $source = $this->source(['max_deviation_bps' => 500]);

        $this->service()->ingest($this->tick($source, 2_650_400_000, observedAt: CarbonImmutable::now()->subSeconds(60)));

        // +13.2% against the previous accepted value, well beyond 5%.
        $result = $this->service()->ingest($this->tick($source, 3_000_000_000));

        $this->assertFalse($result->accepted);
        $this->assertSame(RejectionReason::OUTLIER, $result->reason);
        $this->assertTrue($result->reason->needsManualConfirmation());
    }

    #[Test]
    public function filter_four_rejects_a_value_outside_the_sane_range(): void
    {
        $source = $this->source(['max_sane_value' => 100_000_000_000]);

        $result = $this->service()->ingest($this->tick($source, 200_000_000_000));

        $this->assertFalse($result->accepted);
        $this->assertSame(RejectionReason::OUT_OF_RANGE, $result->reason);
    }

    #[Test]
    public function filter_five_rejects_a_tick_older_than_the_previous_one(): void
    {
        $source = $this->source();

        $this->service()->ingest($this->tick($source, 2_650_400_000, observedAt: CarbonImmutable::now()->subSeconds(10)));

        // Same value, so filter 2 cannot fire; only the timestamp went backwards.
        $result = $this->service()->ingest($this->tick($source, 2_650_400_000, observedAt: CarbonImmutable::now()->subSeconds(30)));

        $this->assertFalse($result->accepted);
        $this->assertSame(RejectionReason::OUT_OF_ORDER, $result->reason);
    }

    #[Test]
    public function filter_three_flags_a_cross_source_disagreement_and_uses_the_median(): void
    {
        $a = $this->source(['code' => 'fx_a', 'priority' => 1], PriceType::USD_IRR);
        $b = $this->source(['code' => 'fx_b', 'priority' => 2], PriceType::USD_IRR);
        $c = $this->source(['code' => 'fx_c', 'priority' => 3], PriceType::USD_IRR);

        $this->service()->ingest($this->tick($a, 620_000, PriceType::USD_IRR));
        $this->service()->ingest($this->tick($b, 621_000, PriceType::USD_IRR));

        // 700,000 disagrees with the peers by ~12.7%, far beyond 2%.
        $result = $this->service()->ingest($this->tick($c, 700_000, PriceType::USD_IRR));

        $this->assertTrue($result->accepted, 'filter 3 flags, it never rejects');
        $this->assertTrue($result->isCrossSourceOutlier);
        $this->assertSame(621_000, $result->medianValue);
        $this->assertSame(621_000, $result->effectiveValue);

        $stored = PriceTick::query()->findOrFail($result->tickId);
        $this->assertSame(700_000, $stored->value);
        $this->assertSame(621_000, $stored->usableValue());
    }

    #[Test]
    public function agreeing_sources_are_not_flagged(): void
    {
        $a = $this->source(['code' => 'fx_a', 'priority' => 1], PriceType::USD_IRR);
        $b = $this->source(['code' => 'fx_b', 'priority' => 2], PriceType::USD_IRR);

        $this->service()->ingest($this->tick($a, 620_000, PriceType::USD_IRR));
        $result = $this->service()->ingest($this->tick($b, 620_500, PriceType::USD_IRR));

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->isCrossSourceOutlier);
    }

    #[Test]
    public function a_rejected_tick_is_still_persisted_with_its_reason(): void
    {
        $source = $this->source(['max_sane_value' => 100_000_000_000]);

        $result = $this->service()->ingest($this->tick($source, 200_000_000_000));

        $this->assertDatabaseHas('price_ticks', [
            'id' => $result->tickId,
            'is_accepted' => 0,
            'rejection_reason' => RejectionReason::OUT_OF_RANGE->value,
        ]);
    }

    /** Resolved per call so Event::fake() inside a test still applies. */
    private function service(): PriceIngestionService
    {
        return $this->app->make(PriceIngestionService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function source(array $attributes = [], PriceType $type = PriceType::OUNCE_USD): PriceSource
    {
        return PriceSourceFactory::new()->ofType($type)->create($attributes);
    }

    private function tick(
        PriceSource $source,
        int $value,
        PriceType $type = PriceType::OUNCE_USD,
        ?CarbonImmutable $observedAt = null,
    ): IncomingTick {
        return IncomingTick::make(
            (int) $source->id,
            $type,
            $value,
            $observedAt ?? CarbonImmutable::now(),
            CarbonImmutable::now(),
        );
    }
}
