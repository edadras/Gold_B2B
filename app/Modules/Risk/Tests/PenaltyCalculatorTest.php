<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Contracts\PenaltyCalculatorInterface;
use App\Modules\Risk\Domain\PenaltyCalculator;
use App\Modules\Shared\ValueObjects\Rial;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F21 — docs/11-appendix/01-formulas.md. */
final class PenaltyCalculatorTest extends TestCase
{
    private PenaltyCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PenaltyCalculator;
    }

    #[Test]
    public function it_matches_the_documented_vector(): void
    {
        // 19,521,900,000 rial, 0.05%/day, 3 days overdue, capped at 10%.
        $penalty = $this->calculator->penalty(19_521_900_000, 3, 50, 10_000);

        $this->assertSame(29_282_850, $penalty);
    }

    #[Test]
    public function the_cap_matches_the_documented_vector(): void
    {
        $this->assertSame(1_952_190_000, $this->calculator->cap(19_521_900_000, 10_000));
    }

    #[Test]
    public function a_long_delay_is_capped(): void
    {
        // 1,000 days at 0.05%/day would be 50% of the amount without the cap.
        $penalty = $this->calculator->penalty(19_521_900_000, 1_000, 50, 10_000);

        $this->assertSame(1_952_190_000, $penalty);
        $this->assertSame($this->calculator->cap(19_521_900_000, 10_000), $penalty);
    }

    #[Test]
    public function it_grows_linearly_with_the_days_overdue(): void
    {
        $oneDay = $this->calculator->penalty(19_521_900_000, 1, 50, 10_000);
        $threeDays = $this->calculator->penalty(19_521_900_000, 3, 50, 10_000);

        $this->assertSame(9_760_950, $oneDay);
        $this->assertSame($oneDay * 3, $threeDays);
    }

    #[Test]
    public function nothing_is_owed_before_the_deadline_passes(): void
    {
        $this->assertSame(0, $this->calculator->penalty(19_521_900_000, 0, 50, 10_000));
        $this->assertSame(0, $this->calculator->penalty(19_521_900_000, -1, 50, 10_000));
        $this->assertSame(0, $this->calculator->penalty(0, 3, 50, 10_000));
    }

    #[Test]
    public function it_falls_back_to_the_configured_platform_rates(): void
    {
        // config('goldb2b.settlement') carries 50 x100k daily and a 10% cap.
        $this->assertSame(29_282_850, $this->calculator->penalty(19_521_900_000, 3));
    }

    #[Test]
    public function the_rial_typed_helper_agrees(): void
    {
        $penalty = $this->calculator->penaltyOn(Rial::fromRial(19_521_900_000), 3);

        $this->assertSame(29_282_850, $penalty->amount);
    }

    #[Test]
    public function it_is_bound_on_the_contract(): void
    {
        $this->assertInstanceOf(
            PenaltyCalculator::class,
            $this->app->make(PenaltyCalculatorInterface::class),
        );
    }
}
