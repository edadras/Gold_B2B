<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests;

use App\Modules\Reputation\Application\TierEvaluator;
use App\Modules\Reputation\Domain\VerificationTier;
use App\Modules\Reputation\Events\TierDemoted;
use App\Modules\Reputation\Events\TierPromoted;
use App\Modules\Reputation\Infrastructure\ReputationStat;
use App\Modules\Reputation\Infrastructure\TierChange;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * §14.4 — promotion is automatic, demotion is a decision.
 *
 * The asymmetry is the point of these tests: no argument, flag or sequence of
 * calls on the automatic path may lower a member's tier.
 */
final class TierEvaluatorTest extends ReputationTestCase
{
    private TierEvaluator $tiers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiers = $this->app->make(TierEvaluator::class);
    }

    #[Test]
    public function a_qualifying_member_is_promoted_automatically(): void
    {
        Event::fake([TierPromoted::class]);

        $this->silverGradeMember(11);

        $change = $this->tiers->promoteIfQualified(11);

        self::assertNotNull($change);
        self::assertSame(TierChange::PROMOTION, $change->direction);
        self::assertSame(VerificationTier::BRONZE, $change->from_tier);
        self::assertSame(VerificationTier::SILVER, $change->to_tier);
        self::assertNull($change->reviewer_user_id, 'an automatic promotion has no reviewer');
        self::assertSame(VerificationTier::SILVER, $this->tierOf(11));

        Event::assertDispatched(TierPromoted::class);
    }

    #[Test]
    public function a_second_pass_changes_nothing(): void
    {
        $this->silverGradeMember(11);

        self::assertNotNull($this->tiers->promoteIfQualified(11));
        self::assertNull($this->tiers->promoteIfQualified(11));
        self::assertSame(1, DB::table('reputation_tier_changes')->count());
    }

    #[Test]
    public function the_automatic_pass_never_lowers_a_tier(): void
    {
        $this->silverGradeMember(11);
        $this->tiers->promoteIfQualified(11);

        // The member's numbers collapse: every settlement since is late and a
        // default is on the record.
        $this->forceStats(11, [
            'settlements_on_time' => 0,
            'settlements_late' => 100,
            'settlements_defaulted' => 3,
            'distinct_counterparties' => 0,
        ]);

        $snapshot = ReputationStat::query()->findOrFail(11)->toSnapshot();

        self::assertSame(
            VerificationTier::BRONZE,
            $this->tiers->qualifiedTierFor($snapshot),
            'the numbers no longer justify SILVER',
        );

        self::assertNull($this->tiers->promoteIfQualified(11));
        self::assertSame(VerificationTier::SILVER, $this->tierOf(11), 'and yet the badge stays until a human acts');
        self::assertSame(VerificationTier::SILVER, $this->tiers->evaluate(11));
    }

    #[Test]
    public function a_demotion_without_a_reviewer_is_refused(): void
    {
        $this->silverGradeMember(11);
        $this->tiers->promoteIfQualified(11);

        $this->expectException(OperationNotPermittedException::class);

        $this->tiers->demote(11, VerificationTier::BRONZE, 0, 'نکول در تسویه شماره ۸۸۲۳۱');
    }

    #[Test]
    public function a_demotion_without_a_usable_reason_is_refused(): void
    {
        $this->silverGradeMember(11);
        $this->tiers->promoteIfQualified(11);

        $this->expectException(OperationNotPermittedException::class);

        $this->tiers->demote(11, VerificationTier::BRONZE, 501, '   ');
    }

    #[Test]
    public function demote_refuses_to_move_a_tier_upward(): void
    {
        $this->silverGradeMember(11);
        $this->tiers->promoteIfQualified(11);

        $this->expectException(OperationNotPermittedException::class);

        $this->tiers->demote(11, VerificationTier::GOLD, 501, 'یک دلیل کاملاً معتبر');
    }

    #[Test]
    public function a_reviewed_demotion_is_recorded_with_its_reason_and_locks_promotion(): void
    {
        Event::fake([TierDemoted::class]);

        $this->silverGradeMember(11);
        $this->tiers->promoteIfQualified(11);

        $change = $this->tiers->demote(
            11,
            VerificationTier::BRONZE,
            501,
            'نکول در تسویه STL-88231 و عدم پاسخ به اختلاف',
        );

        self::assertSame(TierChange::DEMOTION, $change->direction);
        self::assertSame(501, $change->reviewer_user_id);
        self::assertStringContainsString('STL-88231', (string) $change->reason);
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(11));

        Event::assertDispatched(TierDemoted::class, static function (TierDemoted $e): bool {
            return $e->reviewerUserId === 501 && $e->reason !== '';
        });

        // §14.4: a 90-day wait before the automatic pass may lift the member
        // again, even though the statistics still qualify.
        self::assertNull($this->tiers->promoteIfQualified(11));
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(11));

        $this->forceStats(11, [
            'promotion_locked_until' => Carbon::now()->subDay()->toDateTimeString(),
        ]);

        self::assertNotNull($this->tiers->promoteIfQualified(11));
        self::assertSame(VerificationTier::SILVER, $this->tierOf(11));
    }

    #[Test]
    public function the_gap_to_the_next_tier_is_reported_field_by_field(): void
    {
        $this->silverGradeMember(11);
        $this->tiers->promoteIfQualified(11);

        $gap = $this->tiers->nextTierGap(11);

        self::assertSame(VerificationTier::GOLD->value, $gap['next']);
        self::assertContains('min_trades', $gap['unmet']);
        self::assertContains('min_volume_mg', $gap['unmet']);
        self::assertContains('min_distinct_counterparties', $gap['unmet']);
    }

    #[Test]
    public function the_daily_pass_promotes_everyone_who_qualifies(): void
    {
        $this->silverGradeMember(11);
        $this->silverGradeMember(12);
        $this->seedMember(13);

        $promoted = $this->tiers->promoteAll();

        sort($promoted);
        self::assertSame([11, 12], $promoted);
        self::assertSame(VerificationTier::BRONZE, $this->tierOf(13));
    }

    /** A member whose numbers clear every SILVER criterion. */
    private function silverGradeMember(int $organizationId): void
    {
        $this->seedMember($organizationId, memberSinceDays: 200, distinctCounterparties: 8);

        for ($i = 0; $i < 60; $i++) {
            $this->stats->recordSettlement($organizationId, 100_000, onTime: true, settlementMinutes: 20);
        }

        // recordSettlement cannot know about counterparties; the nightly
        // recompute owns that column.
        $this->forceStats($organizationId, ['distinct_counterparties' => 8]);
    }
}
