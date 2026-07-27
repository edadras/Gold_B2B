<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Domain\PremiumCalculator;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** F7 — docs/11-appendix/01-formulas.md. */
final class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator;
    }

    #[Test]
    public function it_matches_the_documented_vector(): void
    {
        $bps = $this->calculator->premiumBps(
            PricePerFineGram::fromRial(78_480_000),
            PricePerFineGram::fromRial(52_831_649),
        );

        // floor(25,648,351 × 10,000 / 52,831,649) = floor(4,854.7) = 4,854
        $this->assertSame(4_854, $bps);
    }

    #[Test]
    public function it_reports_the_raw_rial_gap(): void
    {
        $this->assertSame(
            25_648_351,
            $this->calculator->premiumRial(
                PricePerFineGram::fromRial(78_480_000),
                PricePerFineGram::fromRial(52_831_649),
            ),
        );
    }

    #[Test]
    public function a_market_below_intrinsic_is_a_discount(): void
    {
        $market = PricePerFineGram::fromRial(50_000_000);
        $intrinsic = PricePerFineGram::fromRial(52_831_649);

        $this->assertLessThan(0, $this->calculator->premiumBps($market, $intrinsic));
        $this->assertTrue($this->calculator->isDiscount($market, $intrinsic));
    }

    #[Test]
    public function equal_prices_produce_zero_premium(): void
    {
        $price = PricePerFineGram::fromRial(52_831_649);

        $this->assertSame(0, $this->calculator->premiumBps($price, $price));
        $this->assertFalse($this->calculator->isDiscount($price, $price));
    }

    #[Test]
    public function a_zero_intrinsic_value_yields_null_not_an_error(): void
    {
        // docs/11-appendix/01-formulas.md §1.11 case 6.
        $this->assertNull($this->calculator->premiumBps(
            PricePerFineGram::fromRial(78_480_000),
            PricePerFineGram::zero(),
        ));
    }
}
