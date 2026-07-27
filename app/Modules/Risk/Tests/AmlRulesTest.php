<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlEventType;
use App\Modules\Risk\Aml\Rules\BankChangeThenWithdrawalRule;
use App\Modules\Risk\Aml\Rules\CircularTradeRule;
use App\Modules\Risk\Aml\Rules\ImmediateFlipRule;
use App\Modules\Risk\Aml\Rules\LargeSingleTradeRule;
use App\Modules\Risk\Aml\Rules\RepeatedCounterpartyRule;
use App\Modules\Risk\Aml\Rules\RoundTripTradeRule;
use App\Modules\Risk\Aml\Rules\SelfTradeRule;
use App\Modules\Risk\Aml\Rules\StructuringRule;
use App\Modules\Risk\Aml\Rules\TradeVelocityRule;
use App\Modules\Risk\Aml\Rules\UnusualDailyVolumeRule;
use App\Modules\Risk\Contracts\TradeRecord;
use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Domain\RuleOutcome;
use App\Modules\Risk\Infrastructure\InMemoryTradeHistoryReader;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The rule catalogue of docs/03-domain/12-aml-compliance.md §12.3 / §12.4 / §12.8. */
final class AmlRulesTest extends TestCase
{
    private const ORG_A = 291;

    private const ORG_B = 445;

    private const ORG_C = 112;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-01-05 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── VOL-01 ───────────────────────────────────────────────────────────────

    #[Test]
    public function vol_01_flags_a_trade_above_the_threshold(): void
    {
        $rule = new LargeSingleTradeRule;

        $result = $rule->evaluate($this->tradeContext(fineWeightMg: 12_000_000), ['threshold_mg' => 10_000_000]);

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(FlagSeverity::MEDIUM, $result->severity);
        $this->assertSame(12_000_000, $result->context['fine_weight_mg']);
    }

    #[Test]
    public function vol_01_passes_a_trade_at_the_threshold(): void
    {
        $result = (new LargeSingleTradeRule)->evaluate(
            $this->tradeContext(fineWeightMg: 10_000_000),
            ['threshold_mg' => 10_000_000],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    // ── VOL-02 ───────────────────────────────────────────────────────────────

    #[Test]
    public function vol_02_compares_against_the_members_own_baseline(): void
    {
        $history = new InMemoryTradeHistoryReader;

        // 10 days of 200,000 mg ⇒ a 30-day mean of ~66,000 mg.
        for ($day = 1; $day <= 10; $day++) {
            $history->add($this->record(
                id: 100 + $day,
                seller: self::ORG_A,
                buyer: self::ORG_B,
                weightMg: 200_000,
                at: CarbonImmutable::now()->subDays($day),
            ));
        }

        $rule = new UnusualDailyVolumeRule($history);

        $result = $rule->evaluate(
            $this->tradeContext(fineWeightMg: 5_000_000),
            ['multiplier' => 5, 'lookback_days' => 30, 'min_baseline_mg' => 10_000],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(FlagSeverity::HIGH, $result->severity);
    }

    #[Test]
    public function vol_02_leaves_a_member_without_a_baseline_alone(): void
    {
        $result = (new UnusualDailyVolumeRule(new InMemoryTradeHistoryReader))->evaluate(
            $this->tradeContext(fineWeightMg: 50_000_000),
            ['multiplier' => 5, 'lookback_days' => 30, 'min_baseline_mg' => 100_000],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome, 'the learning period of §12.9 must not flag');
    }

    // ── VEL-01 / VEL-03 ──────────────────────────────────────────────────────

    #[Test]
    public function vel_01_flags_too_many_trades_in_an_hour(): void
    {
        $history = new InMemoryTradeHistoryReader;

        for ($i = 0; $i < 5; $i++) {
            $history->add($this->record(
                id: 200 + $i,
                seller: self::ORG_A,
                buyer: self::ORG_B,
                weightMg: 1_000,
                at: CarbonImmutable::now()->subMinutes(10),
            ));
        }

        $result = (new TradeVelocityRule($history))->evaluate(
            $this->tradeContext(),
            ['max_trades_per_hour' => 5, 'window_minutes' => 60],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(6, $result->context['trade_count']);
    }

    #[Test]
    public function vel_03_flags_repeated_trades_with_one_counterparty(): void
    {
        $history = new InMemoryTradeHistoryReader;

        for ($i = 0; $i < 3; $i++) {
            $history->add($this->record(
                id: 300 + $i,
                seller: self::ORG_A,
                buyer: self::ORG_B,
                weightMg: 1_000,
                at: CarbonImmutable::now()->subMinutes(30),
            ));
        }

        $result = (new RepeatedCounterpartyRule($history))->evaluate(
            $this->tradeContext(),
            ['max_trades_per_day' => 3],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(self::ORG_B, $result->context['counterparty_org_id']);
    }

    // ── PAT-01 ───────────────────────────────────────────────────────────────

    #[Test]
    public function pat_01_detects_a_round_trip(): void
    {
        // A sold 2,400 g to B twenty minutes ago; now B is selling it back.
        $history = new InMemoryTradeHistoryReader([
            $this->record(
                id: 8801,
                seller: self::ORG_A,
                buyer: self::ORG_B,
                weightMg: 2_400_000,
                at: CarbonImmutable::now()->subMinutes(20),
            ),
        ]);

        $context = $this->tradeContext(
            organizationId: self::ORG_B,
            buyer: self::ORG_A,
            seller: self::ORG_B,
            fineWeightMg: 2_400_000,
        );

        $result = (new RoundTripTradeRule($history))->evaluate(
            $context,
            ['window_minutes' => 60, 'weight_tolerance_bps' => 500],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(FlagSeverity::HIGH, $result->severity);
        $this->assertSame(8801, $result->context['reverse_trade_id']);
        $this->assertSame(20, $result->context['minutes_apart']);
    }

    #[Test]
    public function pat_01_ignores_a_reversal_outside_the_window(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(
                id: 8802,
                seller: self::ORG_A,
                buyer: self::ORG_B,
                weightMg: 2_400_000,
                at: CarbonImmutable::now()->subMinutes(240),
            ),
        ]);

        $result = (new RoundTripTradeRule($history))->evaluate(
            $this->tradeContext(
                organizationId: self::ORG_B,
                buyer: self::ORG_A,
                seller: self::ORG_B,
                fineWeightMg: 2_400_000,
            ),
            ['window_minutes' => 60, 'weight_tolerance_bps' => 500],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    #[Test]
    public function pat_01_ignores_a_reversal_of_a_different_size(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(
                id: 8803,
                seller: self::ORG_A,
                buyer: self::ORG_B,
                weightMg: 2_400_000,
                at: CarbonImmutable::now()->subMinutes(20),
            ),
        ]);

        $result = (new RoundTripTradeRule($history))->evaluate(
            $this->tradeContext(
                organizationId: self::ORG_B,
                buyer: self::ORG_A,
                seller: self::ORG_B,
                fineWeightMg: 500_000,
            ),
            ['window_minutes' => 60, 'weight_tolerance_bps' => 500],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    // ── PAT-02 ───────────────────────────────────────────────────────────────

    #[Test]
    public function pat_02_detects_a_three_node_cycle(): void
    {
        // The §12.8 example: 291 → 445 → 112 → 291, same weight, 47 minutes.
        $history = new InMemoryTradeHistoryReader([
            $this->record(9001, self::ORG_A, self::ORG_B, 2_400_000, CarbonImmutable::now()->subMinutes(47)),
            $this->record(9002, self::ORG_B, self::ORG_C, 2_400_000, CarbonImmutable::now()->subMinutes(30)),
            $this->record(9003, self::ORG_C, self::ORG_A, 2_400_000, CarbonImmutable::now()->subMinutes(5)),
        ]);

        $result = (new CircularTradeRule($history))->evaluate(
            $this->tradeContext(organizationId: self::ORG_A),
            ['window_minutes' => 120, 'max_depth' => 5, 'weight_tolerance_bps' => 500],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(FlagSeverity::CRITICAL, $result->severity);
        $this->assertSame([self::ORG_A, self::ORG_B, self::ORG_C, self::ORG_A], $result->context['path']);
        $this->assertSame([9001, 9002, 9003], $result->context['trade_ids']);
        $this->assertSame(3, $result->context['depth']);
    }

    #[Test]
    public function pat_02_ignores_a_chain_that_never_closes(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(9101, self::ORG_A, self::ORG_B, 2_400_000, CarbonImmutable::now()->subMinutes(40)),
            $this->record(9102, self::ORG_B, self::ORG_C, 2_400_000, CarbonImmutable::now()->subMinutes(20)),
        ]);

        $result = (new CircularTradeRule($history))->evaluate(
            $this->tradeContext(organizationId: self::ORG_A),
            ['window_minutes' => 120, 'max_depth' => 5],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    #[Test]
    public function pat_02_ignores_a_cycle_whose_legs_are_different_sizes(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(9201, self::ORG_A, self::ORG_B, 2_400_000, CarbonImmutable::now()->subMinutes(40)),
            $this->record(9202, self::ORG_B, self::ORG_C, 100_000, CarbonImmutable::now()->subMinutes(30)),
            $this->record(9203, self::ORG_C, self::ORG_A, 2_400_000, CarbonImmutable::now()->subMinutes(10)),
        ]);

        $result = (new CircularTradeRule($history))->evaluate(
            $this->tradeContext(organizationId: self::ORG_A),
            ['window_minutes' => 120, 'max_depth' => 5, 'weight_tolerance_bps' => 500],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    #[Test]
    public function pat_02_respects_the_depth_bound(): void
    {
        // A six-hop ring cannot be found with a depth cap of five.
        $ring = [10, 20, 30, 40, 50, 60];
        $records = [];

        foreach ($ring as $index => $org) {
            $records[] = $this->record(
                id: 9300 + $index,
                seller: $org,
                buyer: $ring[($index + 1) % count($ring)],
                weightMg: 1_000_000,
                at: CarbonImmutable::now()->subMinutes(60 - $index),
            );
        }

        $result = (new CircularTradeRule(new InMemoryTradeHistoryReader($records)))->evaluate(
            $this->tradeContext(organizationId: 10),
            ['window_minutes' => 120, 'max_depth' => 5, 'weight_tolerance_bps' => 500],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    // ── PAT-03 ───────────────────────────────────────────────────────────────

    #[Test]
    public function pat_03_detects_an_immediate_flip(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(
                id: 9401,
                seller: self::ORG_C,
                buyer: self::ORG_A,
                weightMg: 1_000_000,
                at: CarbonImmutable::now()->subMinutes(4),
                price: 78_480_000,
            ),
        ]);

        // ORG_A bought four minutes ago and is now selling the same amount on.
        $result = (new ImmediateFlipRule($history))->evaluate(
            $this->tradeContext(
                organizationId: self::ORG_A,
                buyer: self::ORG_B,
                seller: self::ORG_A,
                fineWeightMg: 1_000_000,
            ),
            ['window_minutes' => 10, 'weight_tolerance_bps' => 500, 'max_profit_bps' => 50],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(9401, $result->context['paired_trade_id']);
    }

    #[Test]
    public function pat_03_leaves_a_profitable_flip_alone(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(
                id: 9402,
                seller: self::ORG_C,
                buyer: self::ORG_A,
                weightMg: 1_000_000,
                at: CarbonImmutable::now()->subMinutes(4),
                price: 70_000_000,
            ),
        ]);

        $result = (new ImmediateFlipRule($history))->evaluate(
            $this->tradeContext(
                organizationId: self::ORG_A,
                buyer: self::ORG_B,
                seller: self::ORG_A,
                fineWeightMg: 1_000_000,
            ),
            ['window_minutes' => 10, 'weight_tolerance_bps' => 500, 'max_profit_bps' => 50],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    // ── STR-01 ───────────────────────────────────────────────────────────────

    #[Test]
    public function str_01_detects_five_trades_just_under_the_threshold(): void
    {
        // Threshold 1,000 g; four earlier trades of 950–990 g plus this one.
        $history = new InMemoryTradeHistoryReader([
            $this->record(9501, self::ORG_A, self::ORG_B, 950_000, CarbonImmutable::now()->subHours(3)),
            $this->record(9502, self::ORG_A, self::ORG_B, 960_000, CarbonImmutable::now()->subHours(2)),
            $this->record(9503, self::ORG_A, self::ORG_C, 975_000, CarbonImmutable::now()->subHour()),
            $this->record(9504, self::ORG_A, self::ORG_B, 990_000, CarbonImmutable::now()->subMinutes(30)),
        ]);

        $result = (new StructuringRule($history))->evaluate(
            $this->tradeContext(organizationId: self::ORG_A, fineWeightMg: 985_000),
            [
                'threshold_mg' => 1_000_000,
                'min_count' => 5,
                'lower_bound_bps' => 9_000,
                'upper_bound_bps' => 9_900,
            ],
        );

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(FlagSeverity::HIGH, $result->severity);
        $this->assertSame(5, $result->context['trade_count']);
        $this->assertSame([900_000, 990_000], $result->context['band_mg']);
    }

    #[Test]
    public function str_01_ignores_trades_comfortably_below_the_threshold(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(9601, self::ORG_A, self::ORG_B, 100_000, CarbonImmutable::now()->subHours(3)),
            $this->record(9602, self::ORG_A, self::ORG_B, 120_000, CarbonImmutable::now()->subHours(2)),
            $this->record(9603, self::ORG_A, self::ORG_B, 90_000, CarbonImmutable::now()->subHour()),
            $this->record(9604, self::ORG_A, self::ORG_B, 110_000, CarbonImmutable::now()->subMinutes(30)),
        ]);

        $result = (new StructuringRule($history))->evaluate(
            $this->tradeContext(organizationId: self::ORG_A, fineWeightMg: 105_000),
            ['threshold_mg' => 1_000_000, 'min_count' => 5],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    #[Test]
    public function str_01_needs_the_full_count(): void
    {
        $history = new InMemoryTradeHistoryReader([
            $this->record(9701, self::ORG_A, self::ORG_B, 950_000, CarbonImmutable::now()->subHours(3)),
            $this->record(9702, self::ORG_A, self::ORG_B, 960_000, CarbonImmutable::now()->subHours(2)),
        ]);

        $result = (new StructuringRule($history))->evaluate(
            $this->tradeContext(organizationId: self::ORG_A, fineWeightMg: 985_000),
            ['threshold_mg' => 1_000_000, 'min_count' => 5],
        );

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    // ── CPT-04 ───────────────────────────────────────────────────────────────

    #[Test]
    public function cpt_04_blocks_a_self_trade_attempt(): void
    {
        $result = (new SelfTradeRule)->evaluate(
            $this->tradeContext(
                organizationId: self::ORG_A,
                buyer: self::ORG_A,
                seller: self::ORG_A,
            ),
            [],
        );

        $this->assertSame(RuleOutcome::BLOCK, $result->outcome);
        $this->assertSame(FlagSeverity::CRITICAL, $result->severity);
        $this->assertSame(self::ORG_A, $result->context['organization_id']);
    }

    #[Test]
    public function cpt_04_also_catches_a_self_referencing_counterparty(): void
    {
        $context = new AmlContext(
            eventType: AmlEventType::TRADE_INTENT,
            organizationId: self::ORG_A,
            counterpartyOrgId: self::ORG_A,
            fineWeightMg: 100_000,
        );

        $this->assertSame(RuleOutcome::BLOCK, (new SelfTradeRule)->evaluate($context, [])->outcome);
    }

    #[Test]
    public function cpt_04_passes_an_ordinary_trade(): void
    {
        $result = (new SelfTradeRule)->evaluate($this->tradeContext(), []);

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    // ── BEH-03 ───────────────────────────────────────────────────────────────

    #[Test]
    public function beh_03_flags_a_large_withdrawal_after_a_bank_account_change(): void
    {
        $context = new AmlContext(
            eventType: AmlEventType::WITHDRAWAL,
            organizationId: self::ORG_A,
            amountRial: 5_000_000_000,
            occurredAt: CarbonImmutable::now(),
            metadata: ['bank_account_changed_at' => CarbonImmutable::now()->subHours(2)->toIso8601String()],
        );

        $result = (new BankChangeThenWithdrawalRule)->evaluate($context, [
            'window_hours' => 24,
            'min_amount_rial' => 1_000_000_000,
        ]);

        $this->assertSame(RuleOutcome::FLAG, $result->outcome);
        $this->assertSame(2, $result->context['hours_since_change']);
    }

    #[Test]
    public function beh_03_ignores_a_withdrawal_long_after_the_change(): void
    {
        $context = new AmlContext(
            eventType: AmlEventType::WITHDRAWAL,
            organizationId: self::ORG_A,
            amountRial: 5_000_000_000,
            occurredAt: CarbonImmutable::now(),
            metadata: ['bank_account_changed_at' => CarbonImmutable::now()->subDays(9)->toIso8601String()],
        );

        $result = (new BankChangeThenWithdrawalRule)->evaluate($context, ['window_hours' => 24]);

        $this->assertSame(RuleOutcome::PASS, $result->outcome);
    }

    #[Test]
    public function beh_03_does_not_apply_to_trades(): void
    {
        $result = (new BankChangeThenWithdrawalRule)->evaluate($this->tradeContext(), []);

        $this->assertSame(RuleOutcome::NOT_APPLICABLE, $result->outcome);
    }

    private function tradeContext(
        int $organizationId = self::ORG_A,
        ?int $buyer = null,
        ?int $seller = null,
        int $fineWeightMg = 100_000,
        int $price = 78_480_000,
    ): AmlContext {
        return new AmlContext(
            eventType: AmlEventType::TRADE_EXECUTED,
            organizationId: $organizationId,
            userId: 1,
            counterpartyOrgId: null,
            buyerOrganizationId: $buyer ?? self::ORG_B,
            sellerOrganizationId: $seller ?? $organizationId,
            fineWeightMg: $fineWeightMg,
            pricePerGramRial: $price,
            occurredAt: CarbonImmutable::now(),
            subjectType: 'trade',
            subjectId: 1,
        );
    }

    private function record(
        int $id,
        int $seller,
        int $buyer,
        int $weightMg,
        CarbonImmutable $at,
        int $price = 78_480_000,
    ): TradeRecord {
        return new TradeRecord($id, $buyer, $seller, $weightMg, $price, $at);
    }
}
