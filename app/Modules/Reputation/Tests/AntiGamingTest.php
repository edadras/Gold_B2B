<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use App\Modules\Reputation\Application\TierEvaluator;
use App\Modules\Reputation\Domain\VerificationTier;
use App\Modules\Reputation\Infrastructure\ReputationStat;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §14.8 attack 1: two colluding members trading back and forth to manufacture a
 * reputation.
 *
 * Three defences have to hold together, and this test walks the attack past all
 * three: the minimum countable trade size, volume weighting instead of a raw
 * count, and `distinct_counterparties` — the one figure a closed pair cannot
 * move no matter how many trades it books.
 */
final class AntiGamingTest extends ReputationTestCase
{
    private TierEvaluator $tiers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiers = $this->app->make(TierEvaluator::class);
    }

    #[Test]
    public function a_thousand_tiny_trades_between_the_same_pair_do_not_lift_a_tier(): void
    {
        // Both members are otherwise impeccable: verified, long-standing, and
        // every one of their settlements is on time.
        $this->seedMember(11, memberSinceDays: 400);
        $this->seedMember(22, memberSinceDays: 400);

        $tiny = $this->stats->minCountableTradeMg() - 1;

        for ($i = 0; $i < 1_000; $i++) {
            $this->stats->recordSettlement(11, $tiny, onTime: true, settlementMinutes: 1);
            $this->stats->recordSettlement(22, $tiny, onTime: true, settlementMinutes: 1);
        }

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();

        self::assertSame(0, (int) $row->total_trades, 'sub-threshold trades never enter the statistics');
        self::assertSame(0, (int) $row->total_volume_mg);
        self::assertSame(0, (int) $row->settlements_total);

        self::assertNull($this->tiers->promoteIfQualified(11));
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(11));
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(22));
    }

    #[Test]
    public function a_thousand_countable_trades_with_one_counterparty_still_do_not_lift_a_tier(): void
    {
        $this->seedMember(11, memberSinceDays: 400);

        // The attackers pay the price of the minimum size — a thousand real
        // 10 g trades, 10 kg of volume, all settled on time.
        for ($i = 0; $i < 1_000; $i++) {
            self::assertTrue(
                $this->stats->recordSettlement(11, $this->stats->minCountableTradeMg(), onTime: true, settlementMinutes: 1)
            );
        }

        // The nightly recompute finds exactly one counterparty behind all of it.
        $this->forceStats(11, ['distinct_counterparties' => 1]);

        $row = DB::table('reputation_stats')->where('organization_id', 11)->first();
        self::assertSame(1_000, (int) $row->total_trades);
        self::assertSame(10_000_000, (int) $row->total_volume_mg, 'volume alone would clear SILVER');

        self::assertNull($this->tiers->promoteIfQualified(11));
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(11));

        self::assertContains(
            'min_distinct_counterparties',
            VerificationTier::SILVER->unmetRequirements(
                ReputationStat::query()->findOrFail(11)->toSnapshot()
            ),
        );
    }

    #[Test]
    public function the_same_business_spread_across_real_counterparties_does_lift_the_tier(): void
    {
        $this->seedMember(11, memberSinceDays: 400);

        for ($i = 0; $i < 1_000; $i++) {
            $this->stats->recordSettlement(11, $this->stats->minCountableTradeMg(), onTime: true, settlementMinutes: 1);
        }

        // Same trades, same volume — but genuinely spread.
        $this->forceStats(11, ['distinct_counterparties' => 12]);

        self::assertNotNull($this->tiers->promoteIfQualified(11));
        self::assertSame(VerificationTier::SILVER, $this->tierOf(11));
    }

    #[Test]
    public function count_alone_cannot_beat_volume(): void
    {
        // A member with plenty of trades and plenty of counterparties, but only
        // just above the minimum size on each: volume gates GOLD.
        $this->seedMember(11, memberSinceDays: 400, distinctCounterparties: 40);

        for ($i = 0; $i < 600; $i++) {
            $this->stats->recordSettlement(11, $this->stats->minCountableTradeMg(), onTime: true, settlementMinutes: 1);
        }

        $this->forceStats(11, ['distinct_counterparties' => 40]);

        $this->tiers->promoteIfQualified(11);

        self::assertSame(VerificationTier::SILVER, $this->tierOf(11), '600 trades but only 6 kg of volume');
        self::assertContains('min_volume_mg', $this->tiers->nextTierGap(11)['unmet']);
    }
}
