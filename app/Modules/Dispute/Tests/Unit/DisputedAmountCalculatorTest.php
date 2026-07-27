<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Unit;

use App\Modules\Dispute\Domain\DisputedAmountCalculator;
use App\Modules\Dispute\Domain\DisputeDecision;
use App\Modules\Dispute\Domain\DisputeParty;
use App\Modules\Dispute\Domain\DisputeType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind §13.4, with no database in the way.
 */
final class DisputedAmountCalculatorTest extends TestCase
{
    #[Test]
    public function the_documented_purity_claim_produces_the_documented_figures(): void
    {
        $claim = DisputedAmountCalculator::forPurityClaim(
            declaredFineMg: 500_000,      // 500.000 g fine
            claimedPurityX10k: 9_950,     // عیار ۹۹۵
            actualPurityX10k: 9_850,      // ادعا: عیار ۹۸۵
            pricePerFineGram: 78_480_000,
        );

        self::assertSame(5_025, $claim->fineMg);
        self::assertSame(394_362_000, $claim->rial);
    }

    #[Test]
    public function the_shortfall_is_derived_through_the_physical_gross_weight(): void
    {
        // 500.000 g fine at عیار ۹۹۵ implies 502.513 g of metal (F2, CEIL).
        $gross = DisputedAmountCalculator::grossImpliedBy(500_000, 9_950);

        self::assertSame(502_513, $gross->milligrams);

        // That same metal at عیار ۹۸۵ is worth 494.975 g fine (F1, FLOOR),
        // so the claimant is 5.025 g short — not the 5.000 g the prose rounds to.
        $claim = DisputedAmountCalculator::forPurityClaim(500_000, 9_950, 9_850, 78_480_000);

        self::assertSame(500_000 - 494_975, $claim->fineMg);
        self::assertNotSame(5_000, $claim->fineMg, 'the rounded figure would understate the claim');
    }

    #[Test]
    public function a_claim_of_a_higher_purity_is_not_a_claim(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DisputedAmountCalculator::forPurityClaim(500_000, 9_850, 9_950, 78_480_000);
    }

    #[Test]
    public function a_weight_claim_is_the_plain_difference(): void
    {
        $claim = DisputedAmountCalculator::forWeightClaim(
            declaredFineMg: 250_000,
            actualFineMg: 248_000,
            pricePerFineGram: 78_480_000,
        );

        self::assertSame(2_000, $claim->fineMg);
        self::assertSame(156_960_000, $claim->rial);
    }

    #[Test]
    public function a_weight_claim_alleging_more_gold_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DisputedAmountCalculator::forWeightClaim(250_000, 250_000, 78_480_000);
    }

    #[Test]
    public function valuing_a_shortfall_floors_rather_than_rounds(): void
    {
        // 1 mg at 78,480,000/g is 78,480 rial exactly; 1 mg at 999 rial/g is 0.
        self::assertSame(78_480, DisputedAmountCalculator::valueOf(1, 78_480_000));
        self::assertSame(0, DisputedAmountCalculator::valueOf(1, 999));
    }

    #[Test]
    public function all_thirteen_documented_dispute_types_exist(): void
    {
        $codes = array_map(static fn (DisputeType $t): string => $t->value, DisputeType::cases());

        self::assertSame([
            'WEIGHT_MISMATCH',
            'PURITY_MISMATCH',
            'AMOUNT_MISMATCH',
            'PAYMENT_NOT_RECEIVED',
            'PAYMENT_NOT_MADE',
            'DELIVERY_NOT_MADE',
            'DELIVERY_INCOMPLETE',
            'HALLMARK_MISMATCH',
            'OWNERSHIP_CLAIM',
            'QUALITY_DEFECT',
            'SETTLEMENT_DELAY',
            'UNAUTHORIZED_TRADE',
            'SYSTEM_ERROR',
        ], $codes);
    }

    #[Test]
    public function all_seven_documented_decisions_exist_with_the_right_consequences(): void
    {
        $codes = array_map(static fn (DisputeDecision $d): string => $d->value, DisputeDecision::cases());

        self::assertSame([
            'CLAIM_UPHELD_FULL',
            'CLAIM_UPHELD_PARTIAL',
            'CLAIM_REJECTED',
            'SETTLED_BY_AGREEMENT',
            'WITHDRAWN',
            'SPLIT_LIABILITY',
            'SYSTEM_FAULT',
        ], $codes);

        // §13.8 — who carries the reputational cost.
        self::assertSame(DisputeParty::RESPONDENT, DisputeDecision::CLAIM_UPHELD_FULL->reputationLoser());
        self::assertSame(DisputeParty::CLAIMANT, DisputeDecision::CLAIM_REJECTED->reputationLoser());
        self::assertSame(DisputeParty::BOTH, DisputeDecision::SPLIT_LIABILITY->reputationLoser());

        // «خطای سامانه: بدون اثر بر هیچ‌کدام»
        self::assertNull(DisputeDecision::SYSTEM_FAULT->reputationLoser());
        // «پذیرش سریع اشتباه خود: بدون اثر منفی»
        self::assertNull(DisputeDecision::SETTLED_BY_AGREEMENT->reputationLoser());
        self::assertNull(DisputeDecision::WITHDRAWN->reputationLoser());
    }

    #[Test]
    public function only_verdicts_that_change_something_may_carry_an_award(): void
    {
        self::assertFalse(DisputeDecision::CLAIM_REJECTED->permitsAward());
        self::assertFalse(DisputeDecision::WITHDRAWN->permitsAward());
        self::assertTrue(DisputeDecision::CLAIM_UPHELD_PARTIAL->permitsAward());
        self::assertTrue(DisputeDecision::SPLIT_LIABILITY->permitsAward());
    }

    #[Test]
    public function only_metal_disputes_can_be_settled_by_a_reassay(): void
    {
        self::assertTrue(DisputeType::PURITY_MISMATCH->allowsThirdPartyReassay());
        self::assertTrue(DisputeType::WEIGHT_MISMATCH->allowsThirdPartyReassay());
        self::assertFalse(DisputeType::PAYMENT_NOT_RECEIVED->allowsThirdPartyReassay());
        self::assertFalse(DisputeType::SYSTEM_ERROR->allowsThirdPartyReassay());
    }
}
