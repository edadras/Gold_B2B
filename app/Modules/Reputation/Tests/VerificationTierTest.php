<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use App\Modules\Reputation\Domain\ReputationSnapshot;
use App\Modules\Reputation\Domain\VerificationTier;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** §14.4 — the criteria table, evaluated against a snapshot. */
final class VerificationTierTest extends TestCase
{
    #[Test]
    public function tiers_are_ordered(): void
    {
        self::assertTrue(VerificationTier::PLATINUM->isHigherThan(VerificationTier::GOLD));
        self::assertTrue(VerificationTier::GOLD->isHigherThan(VerificationTier::SILVER));
        self::assertFalse(VerificationTier::SILVER->isHigherThan(VerificationTier::SILVER));
    }

    #[Test]
    public function requirements_accumulate_upwards_with_the_stricter_value_winning(): void
    {
        $gold = VerificationTier::GOLD->cumulativeRequirements();

        self::assertSame(500, $gold['min_trades'], "GOLD's own trade count, not SILVER's");
        self::assertSame(9_900, $gold['min_on_time_rate_bps'], 'GOLD tightens the 95% of SILVER');
        self::assertTrue($gold['kyc_full_verified'], 'inherited from SILVER');
        self::assertSame(0, $gold['max_defaults']);
    }

    #[Test]
    public function bronze_needs_basic_kyc_and_one_trade(): void
    {
        $unverified = $this->snapshot(totalTrades: 5, kycBasicVerified: false);
        $verified = $this->snapshot(totalTrades: 1, kycBasicVerified: true);
        $noTrades = $this->snapshot(totalTrades: 0, kycBasicVerified: true);

        self::assertFalse(VerificationTier::BRONZE->qualifies($unverified));
        self::assertTrue(VerificationTier::BRONZE->qualifies($verified));
        self::assertFalse(VerificationTier::BRONZE->qualifies($noTrades));
        self::assertSame(['min_trades'], VerificationTier::BRONZE->unmetRequirements($noTrades));
    }

    #[Test]
    public function silver_needs_full_kyc_volume_history_and_spread(): void
    {
        $snapshot = $this->silverGrade();

        self::assertTrue(VerificationTier::SILVER->qualifies($snapshot));
        self::assertSame(VerificationTier::SILVER, VerificationTier::highestQualifying($snapshot));
    }

    #[Test]
    public function ninety_five_percent_on_time_is_not_above_ninety_five_percent(): void
    {
        $exactly = $this->silverGrade(settlementsTotal: 100, settlementsOnTime: 95);
        $above = $this->silverGrade(settlementsTotal: 100, settlementsOnTime: 96);

        self::assertSame(9_500, $exactly->onTimeRateBps());
        self::assertContains('min_on_time_rate_bps', VerificationTier::SILVER->unmetRequirements($exactly));
        self::assertTrue(VerificationTier::SILVER->qualifies($above));
    }

    #[Test]
    public function gold_refuses_a_single_default(): void
    {
        $clean = $this->goldGrade();
        $defaulted = $this->goldGrade(settlementsDefaulted: 1);

        self::assertTrue(VerificationTier::GOLD->qualifies($clean));
        self::assertFalse(VerificationTier::GOLD->qualifies($defaulted));
        self::assertSame(['max_defaults'], VerificationTier::GOLD->unmetRequirements($defaulted));
    }

    #[Test]
    public function gold_refuses_a_dispute_rate_at_or_above_one_tenth_of_a_percent(): void
    {
        // 1 lost dispute in 1,000 trades is exactly 0.1% — the doc says "< 0.1%".
        $borderline = $this->goldGrade(totalTrades: 1_000, disputesLost: 1);

        self::assertSame(10, $borderline->disputeRateBps());
        self::assertContains('max_dispute_rate_bps', VerificationTier::GOLD->unmetRequirements($borderline));
    }

    #[Test]
    public function platinum_requires_being_a_market_maker(): void
    {
        $taker = $this->platinumGrade(makerVolumeMg: 200_000_000, takerVolumeMg: 400_000_000);
        $maker = $this->platinumGrade(makerVolumeMg: 400_000_000, takerVolumeMg: 200_000_000);

        self::assertSame(3_333, $taker->makerShareBps());
        self::assertFalse(VerificationTier::PLATINUM->qualifies($taker));
        self::assertTrue(VerificationTier::PLATINUM->qualifies($maker));
        self::assertSame(VerificationTier::PLATINUM, VerificationTier::highestQualifying($maker));
    }

    #[Test]
    public function a_member_with_no_settlements_has_no_on_time_rate_to_boast_about(): void
    {
        $fresh = $this->snapshot(totalTrades: 1, kycBasicVerified: true);

        self::assertSame(0, $fresh->onTimeRateBps(), 'an empty history is not a perfect one');
        self::assertSame(0, $fresh->avgSettlementMinutes());
        self::assertTrue($fresh->isNewMember());
    }

    private function snapshot(
        int $totalTrades = 0,
        int $totalVolumeMg = 0,
        int $settlementsTotal = 0,
        int $settlementsOnTime = 0,
        int $settlementsDefaulted = 0,
        int $disputesLost = 0,
        int $distinctCounterparties = 0,
        int $makerVolumeMg = 0,
        int $takerVolumeMg = 0,
        int $memberSinceDays = 0,
        bool $kycBasicVerified = false,
        bool $kycFullVerified = false,
        bool $bankAccountVerified = false,
    ): ReputationSnapshot {
        return new ReputationSnapshot(
            organizationId: 1,
            totalTrades: $totalTrades,
            totalVolumeMg: $totalVolumeMg,
            settlementsTotal: $settlementsTotal,
            settlementsOnTime: $settlementsOnTime,
            settlementsLate: max(0, $settlementsTotal - $settlementsOnTime),
            settlementsDefaulted: $settlementsDefaulted,
            totalSettlementMinutes: 0,
            disputesInvolved: $disputesLost,
            disputesLost: $disputesLost,
            distinctCounterparties: $distinctCounterparties,
            rfqReceived: 0,
            rfqResponded: 0,
            rfqTotalResponseMinutes: 0,
            quotesAccepted: 0,
            quotesFilled: 0,
            makerVolumeMg: $makerVolumeMg,
            takerVolumeMg: $takerVolumeMg,
            tier: VerificationTier::BRONZE,
            memberSince: Carbon::now()->subDays($memberSinceDays)->toIso8601String(),
            lastActiveAt: Carbon::now()->toIso8601String(),
            kycBasicVerified: $kycBasicVerified,
            kycFullVerified: $kycFullVerified,
            bankAccountVerified: $bankAccountVerified,
        );
    }

    private function silverGrade(int $settlementsTotal = 60, int $settlementsOnTime = 60): ReputationSnapshot
    {
        return $this->snapshot(
            totalTrades: 60,
            totalVolumeMg: 5_000_000,
            settlementsTotal: $settlementsTotal,
            settlementsOnTime: $settlementsOnTime,
            distinctCounterparties: 8,
            memberSinceDays: 120,
            kycBasicVerified: true,
            kycFullVerified: true,
            bankAccountVerified: true,
        );
    }

    private function goldGrade(
        int $totalTrades = 800,
        int $settlementsDefaulted = 0,
        int $disputesLost = 0,
    ): ReputationSnapshot {
        return $this->snapshot(
            totalTrades: $totalTrades,
            totalVolumeMg: 80_000_000,
            settlementsTotal: 800,
            settlementsOnTime: 800,
            settlementsDefaulted: $settlementsDefaulted,
            disputesLost: $disputesLost,
            distinctCounterparties: 30,
            memberSinceDays: 500,
            kycBasicVerified: true,
            kycFullVerified: true,
            bankAccountVerified: true,
        );
    }

    private function platinumGrade(int $makerVolumeMg, int $takerVolumeMg): ReputationSnapshot
    {
        return $this->snapshot(
            totalTrades: 6_000,
            totalVolumeMg: $makerVolumeMg + $takerVolumeMg,
            settlementsTotal: 6_000,
            settlementsOnTime: 6_000,
            distinctCounterparties: 90,
            makerVolumeMg: $makerVolumeMg,
            takerVolumeMg: $takerVolumeMg,
            memberSinceDays: 900,
            kycBasicVerified: true,
            kycFullVerified: true,
            bankAccountVerified: true,
        );
    }
}
