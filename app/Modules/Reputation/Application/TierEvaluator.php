<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Application;

use App\Modules\Reputation\Domain\ReputationSnapshot;
use App\Modules\Reputation\Domain\VerificationTier;
use App\Modules\Reputation\Events\TierDemoted;
use App\Modules\Reputation\Events\TierPromoted;
use App\Modules\Reputation\Infrastructure\ReputationStat;
use App\Modules\Reputation\Infrastructure\TierChange;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Tier movement (docs/03-domain/14-reputation.md §14.4).
 *
 * Promotion is automatic and runs daily. Demotion is not, and the asymmetry is
 * enforced by the shape of the API rather than by a comment:
 *
 *   - `evaluate()` and `promoteIfQualified()` can only ever raise a tier. They
 *     take no reviewer and no reason, so there is no way to express a demotion
 *     through them, and they clamp to the current tier before writing.
 *   - `demote()` demands a reviewer id and a reason, refuses anything that is
 *     not strictly downward, and records the decision.
 *
 * The doc's justification is worth restating: an automatic demotion after one
 * bad week destroys trust in the system faster than the bad week did. Losing a
 * badge is a human decision with a stated reason and a recovery path.
 */
final class TierEvaluator
{
    public function __construct(private readonly StatsUpdater $stats) {}

    /**
     * The tier this member's numbers currently justify, never below the tier it
     * already holds.
     */
    public function evaluate(int $organizationId): VerificationTier
    {
        $stat = $this->stat($organizationId);

        if ($stat === null) {
            return VerificationTier::BRONZE;
        }

        return $this->qualifiedTier($stat->toSnapshot(), $stat->verification_tier);
    }

    /**
     * What the snapshot alone would justify, ignoring the held tier. Used by the
     * profile panel that tells a member which criteria are still unmet — and by
     * a compliance officer weighing a demotion.
     */
    public function qualifiedTierFor(ReputationSnapshot $snapshot): VerificationTier
    {
        return VerificationTier::highestQualifying($snapshot) ?? VerificationTier::BRONZE;
    }

    /**
     * The daily automatic promotion pass.
     *
     * Returns the recorded change, or null when nothing moved. Never lowers a
     * tier: `max()` on rank is the mechanism, not a convention.
     */
    public function promoteIfQualified(int $organizationId): ?TierChange
    {
        $stat = $this->stat($organizationId);

        if ($stat === null) {
            return null;
        }

        $current = $stat->verification_tier;

        // §14.4: a 90-day cooling-off period after a demotion, during which the
        // automatic pass leaves the member where the reviewer put them.
        if ($stat->promotion_locked_until !== null && Carbon::parse($stat->promotion_locked_until)->isFuture()) {
            return null;
        }

        $target = $this->qualifiedTier($stat->toSnapshot(), $current);

        if (! $target->isHigherThan($current)) {
            return null;
        }

        $change = DB::transaction(function () use ($organizationId, $current, $target): TierChange {
            DB::table('reputation_stats')
                ->where('organization_id', $organizationId)
                ->update([
                    'verification_tier' => $target->value,
                    'tier_achieved_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);

            $record = new TierChange;
            $record->fill([
                'organization_id' => $organizationId,
                'from_tier' => $current->value,
                'to_tier' => $target->value,
                'direction' => TierChange::PROMOTION,
            ]);
            $record->save();

            return $record;
        });

        // Fired after the transaction commits (AGENT_BRIEF rule 3): the
        // congratulation notification must never be sent for a rolled-back
        // promotion.
        Event::dispatch(new TierPromoted(
            organizationId: $organizationId,
            fromTier: $current->value,
            toTier: $target->value,
            occurredAt: Carbon::now()->toIso8601String(),
        ));

        return $change;
    }

    /**
     * Deliberate downward move. Requires a reviewer and a reason — both are
     * mandatory arguments, so there is no code path that demotes anonymously.
     *
     * @param  int  $reviewerUserId  the COMPLIANCE_OFFICER who decided
     * @param  string  $reason  shown to the member, per §14.4
     */
    public function demote(
        int $organizationId,
        VerificationTier $toTier,
        int $reviewerUserId,
        string $reason,
    ): TierChange {
        if ($reviewerUserId <= 0) {
            throw new OperationNotPermittedException(
                'A tier demotion must name the reviewer who decided it.'
            );
        }

        $reason = trim($reason);
        $minimumLength = (int) config('goldb2b.reputation.min_demotion_reason_length', 10);

        if (mb_strlen($reason) < $minimumLength) {
            throw new OperationNotPermittedException(
                'A tier demotion must carry a reason the member can be shown.'
            );
        }

        $stat = $this->stat($organizationId);

        if ($stat === null) {
            throw new OperationNotPermittedException('This organisation has no reputation record to demote.');
        }

        $current = $stat->verification_tier;

        if (! $current->isHigherThan($toTier)) {
            throw new OperationNotPermittedException(
                'demote() only moves a tier downward; use the automatic pass to promote.'
            );
        }

        $lockedUntil = Carbon::now()->addDays(
            (int) config('goldb2b.reputation.promotion_lock_days', 90)
        );

        $change = DB::transaction(function () use ($organizationId, $current, $toTier, $reviewerUserId, $reason, $lockedUntil): TierChange {
            DB::table('reputation_stats')
                ->where('organization_id', $organizationId)
                ->update([
                    'verification_tier' => $toTier->value,
                    'tier_achieved_at' => Carbon::now()->toDateTimeString(),
                    'promotion_locked_until' => $lockedUntil->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);

            $record = new TierChange;
            $record->fill([
                'organization_id' => $organizationId,
                'from_tier' => $current->value,
                'to_tier' => $toTier->value,
                'direction' => TierChange::DEMOTION,
                'reviewer_user_id' => $reviewerUserId,
                'reason' => $reason,
                'promotion_locked_until' => $lockedUntil->toDateTimeString(),
            ]);
            $record->save();

            return $record;
        });

        Event::dispatch(new TierDemoted(
            organizationId: $organizationId,
            fromTier: $current->value,
            toTier: $toTier->value,
            reviewerUserId: $reviewerUserId,
            reason: $reason,
            promotionLockedUntil: $lockedUntil->toIso8601String(),
            occurredAt: Carbon::now()->toIso8601String(),
        ));

        return $change;
    }

    /**
     * Criteria the member has not met for the tier above its current one.
     *
     * @return array{next: ?string, unmet: list<string>}
     */
    public function nextTierGap(int $organizationId): array
    {
        $stat = $this->stat($organizationId);

        if ($stat === null) {
            return ['next' => VerificationTier::BRONZE->value, 'unmet' => ['min_trades']];
        }

        $next = null;

        foreach (VerificationTier::cases() as $tier) {
            if ($tier->isHigherThan($stat->verification_tier)) {
                $next = $tier;
                break;
            }
        }

        if ($next === null) {
            return ['next' => null, 'unmet' => []];
        }

        return [
            'next' => $next->value,
            'unmet' => $next->unmetRequirements($stat->toSnapshot()),
        ];
    }

    /** @return list<int> organisation ids that moved up */
    public function promoteAll(): array
    {
        $promoted = [];

        ReputationStat::query()->orderBy('organization_id')->chunkById(200, function ($stats) use (&$promoted): void {
            foreach ($stats as $stat) {
                if ($this->promoteIfQualified($stat->organization_id) !== null) {
                    $promoted[] = $stat->organization_id;
                }
            }
        }, 'organization_id');

        return $promoted;
    }

    private function qualifiedTier(ReputationSnapshot $snapshot, VerificationTier $current): VerificationTier
    {
        $qualified = $this->qualifiedTierFor($snapshot);

        // The clamp that makes an accidental demotion impossible on this path.
        return $qualified->isHigherThan($current) ? $qualified : $current;
    }

    private function stat(int $organizationId): ?ReputationStat
    {
        $this->stats->ensure($organizationId);

        return ReputationStat::query()->find($organizationId);
    }
}
