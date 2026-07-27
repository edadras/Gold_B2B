<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Domain\CollateralCoverage;
use App\Modules\Risk\Domain\CollateralType;
use App\Modules\Risk\Domain\CoverageStatus;
use App\Modules\Risk\Domain\ExposureCalculator;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** F18 and F20 — docs/11-appendix/01-formulas.md, §11.3 / §11.6 / §11.7. */
final class ExposureAndCollateralTest extends TestCase
{
    #[Test]
    public function exposure_is_settlements_plus_open_sell_orders(): void
    {
        $exposure = (new ExposureCalculator)->combine(
            settlementGoldMg: 2_000_000,
            openOrderGoldMg: 400_000,
            settlementRial: 150_000_000_000,
            openOrderRial: 20_000_000_000,
        );

        $this->assertSame(2_400_000, $exposure->goldMg);
        $this->assertSame(170_000_000_000, $exposure->rial);
        $this->assertSame(2_000_000, $exposure->settlementGoldMg);
        $this->assertSame(400_000, $exposure->openOrderGoldMg);
    }

    #[Test]
    public function exposure_is_valued_in_rial_at_the_current_price(): void
    {
        $calculator = new ExposureCalculator;
        $exposure = $calculator->combine(2_000_000, 400_000, 170_000_000_000, 0);

        // floor(2,400,000 mg × 78,480,000 / 1,000) = 188,352,000,000
        $value = $calculator->valueInRial($exposure, PricePerFineGram::fromRial(78_480_000));

        $this->assertSame(188_352_000_000 + 170_000_000_000, $value);
    }

    #[Test]
    public function utilisation_matches_the_admin_panel_example(): void
    {
        // §11.9: 2,400 g against a 5,000 g ceiling is 48%.
        $this->assertSame(4_800, (new ExposureCalculator)->utilisationBps(2_400_000, 5_000_000));
        $this->assertNull((new ExposureCalculator)->utilisationBps(2_400_000, 0));
    }

    #[Test]
    #[DataProvider('coverageBands')]
    public function coverage_thresholds(int $collateral, int $exposure, int $expectedBps, CoverageStatus $expected): void
    {
        $assessment = (new CollateralCoverage)->evaluate($collateral, $exposure);

        $this->assertSame($expectedBps, $assessment->ratioBps);
        $this->assertSame($expected, $assessment->status);
    }

    /** @return iterable<string, array{int, int, int, CoverageStatus}> */
    public static function coverageBands(): iterable
    {
        yield 'well covered' => [200_000, 100_000, 20_000, CoverageStatus::HEALTHY];
        yield 'exactly at the healthy floor' => [150_000, 100_000, 15_000, CoverageStatus::HEALTHY];
        yield 'just below healthy' => [149_990, 100_000, 14_999, CoverageStatus::WARNING];
        yield 'warning floor' => [120_000, 100_000, 12_000, CoverageStatus::WARNING];
        yield 'just below warning' => [119_990, 100_000, 11_999, CoverageStatus::MARGIN_CALL];
        yield 'margin call floor' => [110_000, 100_000, 11_000, CoverageStatus::MARGIN_CALL];
        yield 'below margin call' => [109_990, 100_000, 10_999, CoverageStatus::PARTIAL_LIQUIDATION];
        yield 'uncovered' => [0, 100_000, 0, CoverageStatus::PARTIAL_LIQUIDATION];
    }

    #[Test]
    public function no_exposure_means_no_ratio_and_no_margin_call(): void
    {
        $assessment = (new CollateralCoverage)->evaluate(500_000, 0);

        $this->assertNull($assessment->ratioBps);
        $this->assertSame(CoverageStatus::HEALTHY, $assessment->status);
        $this->assertFalse($assessment->requiresMarginCall());
        $this->assertSame(0, $assessment->shortfallToHealthyRial());
    }

    #[Test]
    public function a_margin_call_reports_the_shortfall_and_a_four_hour_deadline(): void
    {
        $assessment = (new CollateralCoverage)->evaluate(115_000, 100_000);

        $this->assertSame(CoverageStatus::MARGIN_CALL, $assessment->status);
        $this->assertTrue($assessment->requiresMarginCall());
        $this->assertSame(4, $assessment->status->topUpDeadlineHours());
        $this->assertSame(35_000, $assessment->shortfallToHealthyRial());
    }

    #[Test]
    public function below_the_lowest_band_new_trading_stops(): void
    {
        $assessment = (new CollateralCoverage)->evaluate(100_000, 100_000);

        $this->assertSame(CoverageStatus::PARTIAL_LIQUIDATION, $assessment->status);
        $this->assertTrue($assessment->status->blocksNewTrades());
    }

    #[Test]
    public function acceptance_factors_follow_the_documented_haircuts(): void
    {
        $this->assertSame(9_000, CollateralType::GOLD_VAULT->defaultAcceptanceFactorBps());
        $this->assertSame(10_000, CollateralType::RIAL_DEPOSIT->defaultAcceptanceFactorBps());
        $this->assertSame(9_500, CollateralType::BANK_GUARANTEE->defaultAcceptanceFactorBps());
        $this->assertSame(5_000, CollateralType::THIRD_PARTY_GUARANTEE->defaultAcceptanceFactorBps());
    }

    #[Test]
    public function the_credit_ceiling_matches_the_documented_example(): void
    {
        // §11.6: 500 g unsecured + 2,000 g of vault gold at 90% = 2,300 g.
        $ceiling = (new CollateralCoverage)->creditCeilingMg(500_000, [
            [2_000_000, CollateralType::GOLD_VAULT->defaultAcceptanceFactorBps()],
        ]);

        $this->assertSame(2_300_000, $ceiling);
    }
}
