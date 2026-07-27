<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Application\CircuitBreakerEvaluator;
use App\Modules\Pricing\Events\CircuitBreakerTriggered;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F24 — docs/11-appendix/01-formulas.md. */
final class CircuitBreakerEvaluatorTest extends TestCase
{
    use WithFaker;

    #[Test]
    public function it_matches_the_documented_vector_and_halts(): void
    {
        Event::fake([CircuitBreakerTriggered::class]);

        $decision = $this->evaluator(reference: 78_000_000)->evaluate(
            instrumentId: 1,
            current: PricePerFineGram::fromRial(80_500_000),
            thresholdBps: 300,
        );

        // floor(2,500,000 × 10,000 / 78,000,000) = 320 bps
        $this->assertSame(320, $decision->deviationBps);
        $this->assertSame(300, $decision->thresholdBps);
        $this->assertTrue($decision->shouldHalt);

        Event::assertDispatched(
            CircuitBreakerTriggered::class,
            fn (CircuitBreakerTriggered $e): bool => $e->deviationBps === 320,
        );
    }

    #[Test]
    public function a_move_inside_the_band_does_not_halt(): void
    {
        Event::fake([CircuitBreakerTriggered::class]);

        $decision = $this->evaluator(reference: 78_000_000)->evaluate(
            instrumentId: 1,
            current: PricePerFineGram::fromRial(79_000_000),
            thresholdBps: 300,
        );

        $this->assertSame(128, $decision->deviationBps);
        $this->assertFalse($decision->shouldHalt);
        Event::assertNotDispatched(CircuitBreakerTriggered::class);
    }

    #[Test]
    public function a_fall_is_measured_the_same_way_as_a_rise(): void
    {
        $decision = $this->evaluator(reference: 78_000_000)->evaluate(
            instrumentId: 1,
            current: PricePerFineGram::fromRial(75_500_000),
            thresholdBps: 300,
        );

        $this->assertSame(320, $decision->deviationBps);
        $this->assertTrue($decision->shouldHalt);
    }

    #[Test]
    public function exactly_on_the_threshold_does_not_halt(): void
    {
        $decision = $this->evaluator(reference: 10_000_000)->evaluate(
            instrumentId: 1,
            current: PricePerFineGram::fromRial(10_300_000),
            thresholdBps: 300,
        );

        $this->assertSame(300, $decision->deviationBps);
        $this->assertFalse($decision->shouldHalt, 'the rule is strictly greater than');
    }

    #[Test]
    public function it_falls_back_to_the_stored_reference_price(): void
    {
        $decision = $this->evaluator(reference: 78_000_000)
            ->evaluate(1, PricePerFineGram::fromRial(80_500_000));

        $this->assertSame(78_000_000, $decision->referenceRial);
        $this->assertSame(
            (int) config('goldb2b.pricing.circuit_breaker_bps'),
            $decision->thresholdBps,
        );
    }

    #[Test]
    public function without_a_reference_it_declines_to_decide(): void
    {
        Event::fake([CircuitBreakerTriggered::class]);

        $decision = $this->evaluator(reference: null)
            ->evaluate(1, PricePerFineGram::fromRial(80_500_000));

        $this->assertFalse($decision->shouldHalt);
        $this->assertSame(0, $decision->deviationBps);
        Event::assertNotDispatched(CircuitBreakerTriggered::class);
    }

    private function evaluator(?int $reference): CircuitBreakerEvaluator
    {
        return new CircuitBreakerEvaluator(
            new FixedPriceReader(reference: $reference),
            $this->app->make(Dispatcher::class),
        );
    }
}
