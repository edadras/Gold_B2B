<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Domain\IntrinsicValueCalculator;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** F6 — docs/11-appendix/01-formulas.md. */
final class IntrinsicValueCalculatorTest extends TestCase
{
    private IntrinsicValueCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new IntrinsicValueCalculator;
    }

    #[Test]
    public function it_matches_the_documented_vector(): void
    {
        // ounce $2,650.40 and USD/IRR 620,000 ⇒ 52,831,649 rial per fine gram.
        $price = $this->calculator->pricePerFineGram(2_650_400_000, 620_000);

        $this->assertSame(52_831_649, $price->rial);
    }

    #[Test]
    public function it_floors_rather_than_rounds(): void
    {
        // 2,650,400,000 × 620,000 × 10 = 16,432,480,000,000,000
        // 16,432,480,000,000,000 / 311,034,768 = 52,831,649.71…
        $exactFloor = intdiv(16_432_480_000_000_000, 311_034_768);

        $this->assertSame(
            $exactFloor,
            $this->calculator->pricePerFineGram(2_650_400_000, 620_000)->rial,
        );
    }

    #[Test]
    public function it_scales_to_a_purity(): void
    {
        $fine = PricePerFineGram::fromRial(52_831_649);

        $at750 = $this->calculator->pricePerGramAtPurity($fine, Purity::fromPpt(750));

        // floor(52,831,649 × 7,500 / 10,000)
        $this->assertSame(39_623_736, $at750->rial);
    }

    #[Test]
    public function pure_gold_keeps_the_full_price(): void
    {
        $fine = PricePerFineGram::fromRial(52_831_649);

        $this->assertSame(
            52_831_649,
            $this->calculator->pricePerGramAtPurity($fine, Purity::pure())->rial,
        );
    }

    #[Test]
    public function it_is_monotonic_in_both_inputs(): void
    {
        $base = $this->calculator->pricePerFineGram(2_650_400_000, 620_000)->rial;

        $this->assertGreaterThan($base, $this->calculator->pricePerFineGram(2_650_500_000, 620_000)->rial);
        $this->assertGreaterThan($base, $this->calculator->pricePerFineGram(2_650_400_000, 621_000)->rial);
    }

    #[Test]
    public function it_rejects_a_non_positive_ounce_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->pricePerFineGram(0, 620_000);
    }

    #[Test]
    public function it_rejects_a_non_positive_fx_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->pricePerFineGram(2_650_400_000, 0);
    }
}
