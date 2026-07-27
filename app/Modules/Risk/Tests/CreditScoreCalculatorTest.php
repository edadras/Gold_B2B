<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Contracts\MemberActivityStats;
use App\Modules\Risk\Domain\CreditScoreCalculator;
use App\Modules\Risk\Domain\RiskLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** F19 — docs/11-appendix/01-formulas.md. */
final class CreditScoreCalculatorTest extends TestCase
{
    private CreditScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CreditScoreCalculator;
    }

    #[Test]
    public function a_perfect_member_scores_exactly_one_thousand(): void
    {
        $score = $this->calculator->calculate($this->perfect());

        $this->assertSame(1_000, $score->score);
        $this->assertSame(350, $score->pointsFor('on_time_rate'));
        $this->assertSame(150, $score->pointsFor('tenure'));
        $this->assertSame(150, $score->pointsFor('volume'));
        $this->assertSame(150, $score->pointsFor('dispute_clean'));
        $this->assertSame(100, $score->pointsFor('kyc_complete'));
        $this->assertSame(50, $score->pointsFor('cp_diversity'));
        $this->assertSame(50, $score->pointsFor('aml_clean'));
        $this->assertSame(RiskLevel::LOW, $score->riskLevel());
    }

    #[Test]
    public function the_weights_sum_to_one_thousand(): void
    {
        $score = $this->calculator->calculate($this->perfect());

        $this->assertSame(1_000, array_sum($score->breakdown));
    }

    #[Test]
    public function the_worst_possible_member_scores_zero(): void
    {
        $score = $this->calculator->calculate(new MemberActivityStats(
            settlementsOnTime: 0,
            settlementsTotal: 10,
            monthsActive: 0,
            totalVolumeMg: 0,
            disputesLost: 5,
            totalTrades: 10,
            kycVerifiedItems: 0,
            kycTotalItems: 8,
            distinctCounterparties: 0,
            activeAmlFlags: 3,
        ));

        $this->assertSame(0, $score->score);
        $this->assertSame(RiskLevel::CRITICAL, $score->riskLevel());
    }

    #[Test]
    #[DataProvider('boundaryCases')]
    public function the_score_always_stays_inside_zero_to_one_thousand(MemberActivityStats $stats): void
    {
        $score = $this->calculator->calculate($stats);

        $this->assertGreaterThanOrEqual(0, $score->score);
        $this->assertLessThanOrEqual(1_000, $score->score);

        foreach ($score->breakdown as $component => $points) {
            $this->assertGreaterThanOrEqual(0, $points, $component);
        }
    }

    /** @return iterable<string, array{MemberActivityStats}> */
    public static function boundaryCases(): iterable
    {
        yield 'empty member' => [new MemberActivityStats];

        yield 'perfect member' => [new MemberActivityStats(
            settlementsOnTime: 100, settlementsTotal: 100, monthsActive: 240,
            totalVolumeMg: 10_000_000_000, disputesLost: 0, totalTrades: 5_000,
            kycVerifiedItems: 12, kycTotalItems: 12, distinctCounterparties: 400,
            activeAmlFlags: 0,
        )];

        yield 'inputs beyond their caps' => [new MemberActivityStats(
            settlementsOnTime: 500, settlementsTotal: 100, monthsActive: 10_000,
            totalVolumeMg: PHP_INT_MAX >> 8, disputesLost: 0, totalTrades: 1,
            kycVerifiedItems: 99, kycTotalItems: 3, distinctCounterparties: 9_999,
            activeAmlFlags: 0,
        )];

        yield 'more disputes than trades' => [new MemberActivityStats(
            settlementsOnTime: 1, settlementsTotal: 2, monthsActive: 5,
            totalVolumeMg: 5_000_000, disputesLost: 50, totalTrades: 3,
            kycVerifiedItems: 1, kycTotalItems: 4, distinctCounterparties: 2,
            activeAmlFlags: 1,
        )];

        yield 'no settlements but plenty of trades' => [new MemberActivityStats(
            settlementsOnTime: 0, settlementsTotal: 0, monthsActive: 12,
            totalVolumeMg: 100_000_000, disputesLost: 0, totalTrades: 400,
            kycVerifiedItems: 6, kycTotalItems: 6, distinctCounterparties: 11,
            activeAmlFlags: 0,
        )];
    }

    #[Test]
    public function the_score_is_monotonic_in_the_on_time_rate(): void
    {
        $this->assertMonotonic(
            fn (int $onTime): MemberActivityStats => $this->perfect(['settlementsOnTime' => $onTime]),
            [0, 10, 25, 50, 75, 99, 100],
        );
    }

    #[Test]
    public function the_score_is_monotonic_in_tenure(): void
    {
        $this->assertMonotonic(
            fn (int $months): MemberActivityStats => $this->perfect(['monthsActive' => $months]),
            [0, 1, 6, 12, 23, 24, 60],
        );
    }

    #[Test]
    public function the_score_is_monotonic_in_volume(): void
    {
        $this->assertMonotonic(
            fn (int $mg): MemberActivityStats => $this->perfect(['totalVolumeMg' => $mg]),
            [0, 1_000, 999_000, 1_000_000, 10_000_000, 1_000_000_000, 1_000_000_000_000],
        );
    }

    #[Test]
    public function the_score_is_monotonic_in_kyc_completeness(): void
    {
        $this->assertMonotonic(
            fn (int $verified): MemberActivityStats => $this->perfect(['kycVerifiedItems' => $verified]),
            [0, 1, 5, 9, 10],
        );
    }

    #[Test]
    public function the_score_is_monotonic_in_counterparty_diversity(): void
    {
        $this->assertMonotonic(
            fn (int $distinct): MemberActivityStats => $this->perfect(['distinctCounterparties' => $distinct]),
            [0, 1, 5, 10, 19, 20, 100],
        );
    }

    #[Test]
    public function the_score_falls_as_lost_disputes_rise(): void
    {
        $previous = null;

        foreach ([0, 1, 2, 5, 10] as $disputes) {
            $score = $this->calculator->calculate($this->perfect(['disputesLost' => $disputes]))->score;

            if ($previous !== null) {
                $this->assertLessThanOrEqual($previous, $score, "disputes={$disputes}");
            }

            $previous = $score;
        }
    }

    #[Test]
    public function a_single_open_flag_costs_the_whole_aml_component(): void
    {
        $clean = $this->calculator->calculate($this->perfect());
        $flagged = $this->calculator->calculate($this->perfect(['activeAmlFlags' => 1]));

        $this->assertSame(50, $clean->pointsFor('aml_clean'));
        $this->assertSame(0, $flagged->pointsFor('aml_clean'));
        $this->assertSame(950, $flagged->score);
    }

    #[Test]
    public function score_bands_map_to_risk_levels(): void
    {
        $this->assertSame(RiskLevel::LOW, RiskLevel::fromCreditScore(800));
        $this->assertSame(RiskLevel::MEDIUM, RiskLevel::fromCreditScore(799));
        $this->assertSame(RiskLevel::MEDIUM, RiskLevel::fromCreditScore(500));
        $this->assertSame(RiskLevel::HIGH, RiskLevel::fromCreditScore(499));
        $this->assertSame(RiskLevel::HIGH, RiskLevel::fromCreditScore(200));
        $this->assertSame(RiskLevel::CRITICAL, RiskLevel::fromCreditScore(199));
        $this->assertSame(RiskLevel::CRITICAL, RiskLevel::fromCreditScore(0));
    }

    /**
     * @param  callable(int): MemberActivityStats  $build
     * @param  list<int>  $inputs
     */
    private function assertMonotonic(callable $build, array $inputs): void
    {
        $previous = null;

        foreach ($inputs as $input) {
            $score = $this->calculator->calculate($build($input))->score;

            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($previous, $score, "input={$input}");
            }

            $previous = $score;
        }
    }

    /** @param array<string, int> $overrides */
    private function perfect(array $overrides = []): MemberActivityStats
    {
        $base = [
            'settlementsOnTime' => 100,
            'settlementsTotal' => 100,
            'monthsActive' => 24,
            'totalVolumeMg' => 1_000_000_000,   // 10^6 grams
            'disputesLost' => 0,
            'totalTrades' => 1_000,
            'kycVerifiedItems' => 10,
            'kycTotalItems' => 10,
            'distinctCounterparties' => 20,
            'activeAmlFlags' => 0,
        ];

        return new MemberActivityStats(...array_merge($base, $overrides));
    }
}
