<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use App\Modules\Reputation\Application\PublicProfileService;
use App\Modules\Reputation\Domain\VerificationTier;
use PHPUnit\Framework\Attributes\Test;

/**
 * §14.9 — what a public profile may and may not contain.
 *
 * §14.2 calls a leak here a serious compliance failure ("tipping-off"), so the
 * payload is asserted key by key rather than "contains what we expect": a new
 * column added upstream must break this test, not slip out silently.
 */
final class PublicProfilePrivacyTest extends ReputationTestCase
{
    private PublicProfileService $profiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profiles = $this->app->make(PublicProfileService::class);
    }

    #[Test]
    public function the_public_payload_is_exactly_the_permitted_field_set(): void
    {
        $this->seedMember(11, distinctCounterparties: 142);
        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 23);

        $payload = $this->profiles->profile(11)->toArray();

        self::assertSame([
            'organization_id',
            'verification_tier',
            'tier_label',
            'tier_badge',
            'total_trades',
            'total_volume_mg',
            'on_time_settlement_rate_bps',
            'dispute_rate_bps',
            'avg_settlement_minutes',
            'distinct_counterparties',
            'rfq_response_rate_bps',
            'rfq_avg_response_minutes',
            'quote_fill_rate_bps',
            'member_since',
            'last_active_at',
            'is_active',
            'is_new_member',
        ], array_keys($payload));
    }

    #[Test]
    public function the_serialised_profile_carries_no_forbidden_field(): void
    {
        $this->seedMember(11, distinctCounterparties: 142);
        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 23);

        $json = (string) json_encode($this->profiles->profile(11)->toArray());

        foreach ([
            'balance', 'gold_balance', 'rial', 'counterparty_org', 'counterparties_list',
            'price', 'aml', 'suspend', 'kyc', 'national_id', 'credit', 'score',
            'internal_note', 'risk_level', 'limit',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $json,
                "a public reputation profile must never expose '{$forbidden}'",
            );
        }
    }

    #[Test]
    public function the_kyc_flags_that_drive_tiers_are_never_published(): void
    {
        $this->seedMember(11, kycBasic: true, kycFull: true, bank: true);

        $payload = $this->profiles->profile(11)->toArray();

        self::assertArrayNotHasKey('kyc_basic_verified', $payload);
        self::assertArrayNotHasKey('kyc_full_verified', $payload);
        self::assertArrayNotHasKey('bank_account_verified', $payload);
    }

    #[Test]
    public function rates_are_integer_basis_points_not_floats(): void
    {
        $this->seedMember(11);

        for ($i = 0; $i < 500; $i++) {
            $this->stats->recordSettlement(11, 100_000, onTime: $i > 0, settlementMinutes: 23);
        }

        $profile = $this->profiles->profile(11);

        self::assertIsInt($profile->onTimeSettlementRateBps);
        self::assertSame(9_980, $profile->onTimeSettlementRateBps, '499/500 = 99.8%');
        self::assertSame(23, $profile->avgSettlementMinutes);
    }

    #[Test]
    public function an_unknown_member_gets_an_empty_bronze_profile_rather_than_an_error(): void
    {
        $profile = $this->profiles->profile(9_999);

        self::assertSame(VerificationTier::BRONZE->value, $profile->verificationTier);
        self::assertSame(0, $profile->totalTrades);
        self::assertFalse($profile->isActive);
        self::assertTrue($profile->isNewMember);
    }

    #[Test]
    public function profiles_can_be_fetched_in_bulk_for_a_quote_list(): void
    {
        $this->seedMember(11);
        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 5);

        $profiles = $this->profiles->profiles([11, 22]);

        self::assertCount(2, $profiles);
        self::assertSame(1, $profiles[11]->totalTrades);
        self::assertSame(0, $profiles[22]->totalTrades, 'a member with no record still gets a card');
    }

    #[Test]
    public function a_member_sees_its_own_numerators_and_denominators(): void
    {
        $this->seedMember(11);
        $this->stats->recordSettlement(11, 250_000, onTime: true, settlementMinutes: 5);
        $this->stats->recordSettlement(11, 250_000, onTime: false, settlementMinutes: 900);

        $own = $this->profiles->ownStatistics(11);

        // §14.9: the member must be able to check the arithmetic behind a rate.
        self::assertSame(2, $own['settlements_total']);
        self::assertSame(1, $own['settlements_on_time']);
        self::assertSame(1, $own['settlements_late']);
        self::assertSame(5_000, $own['on_time_settlement_rate_bps']);
        self::assertSame($this->stats->minCountableTradeMg(), $own['min_countable_trade_mg']);
        // Still nothing about balances or counterparties.
        self::assertArrayNotHasKey('gold_balance_mg', $own);
    }
}
