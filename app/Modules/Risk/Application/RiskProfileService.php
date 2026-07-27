<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Contracts\MemberActivityReaderInterface;
use App\Modules\Risk\Domain\CreditScore;
use App\Modules\Risk\Domain\CreditScoreCalculator;
use App\Modules\Risk\Domain\RiskLevel;
use App\Modules\Risk\Events\CreditScoreUpdated;
use App\Modules\Risk\Events\RiskProfileChanged;
use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use App\Modules\Risk\Infrastructure\Models\TradingLimit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Creates and maintains risk_profiles. docs/03-domain/11-risk-credit.md §11.1–§11.2.
 */
final class RiskProfileService
{
    public function __construct(
        private readonly CreditScoreCalculator $scores,
        private readonly MemberActivityReaderInterface $activity,
        private readonly Dispatcher $events,
    ) {}

    /** New members always start at MEDIUM, never LOW (§11.1). */
    public function createFor(int $organizationId, ?RiskLevel $level = null): RiskProfile
    {
        $level ??= RiskLevel::forNewMember();
        $defaults = TradingLimit::defaultsFor($level);

        return DB::transaction(fn (): RiskProfile => RiskProfile::query()->create(array_merge(
            $defaults,
            [
                'organization_id' => $organizationId,
                'risk_level' => $level->value,
                'credit_score' => 0,
                'is_trading_allowed' => $level->canTrade(),
                'next_review_at' => CarbonImmutable::now()->addDays(90),
            ],
        )));
    }

    /**
     * Recomputes F19 and stores it. The risk level is only ever lowered
     * automatically — §11.2 requires Compliance to sign off on an upgrade, so a
     * score that improves updates the number and leaves the level alone.
     */
    public function recalculateScore(int $organizationId): CreditScore
    {
        $profile = $this->profile($organizationId);
        $score = $this->scores->calculate($this->activity->statsFor($organizationId));

        $previousScore = $profile->credit_score;
        $previousLevel = $profile->risk_level;
        $suggested = $score->riskLevel();
        $downgrade = $this->isDowngrade($previousLevel, $suggested);

        DB::transaction(function () use ($profile, $score, $suggested, $downgrade): void {
            $attributes = ['credit_score' => $score->score];

            if ($downgrade) {
                $attributes = array_merge(
                    $attributes,
                    TradingLimit::defaultsFor($suggested),
                    [
                        'risk_level' => $suggested->value,
                        'is_trading_allowed' => $suggested->canTrade(),
                    ],
                );
            }

            $profile->forceFill($attributes)->save();
        });

        $this->events->dispatch(new CreditScoreUpdated(
            organizationId: $organizationId,
            previousScore: $previousScore,
            newScore: $score->score,
            reason: 'RECALCULATED',
            breakdown: $score->breakdown,
        ));

        if ($downgrade) {
            $this->events->dispatch(new RiskProfileChanged(
                organizationId: $organizationId,
                previousLevel: $previousLevel->value,
                newLevel: $suggested->value,
                isTradingAllowed: $suggested->canTrade(),
                reason: 'CREDIT_SCORE_DOWNGRADE',
                automatic: true,
            ));
        }

        return $score;
    }

    /**
     * Immediate penalties of §11.2 — a late settlement, a lost dispute, a
     * default. Negative $delta lowers the score; the floor is 0.
     */
    public function adjustScore(int $organizationId, int $delta, string $reason): int
    {
        $profile = $this->profile($organizationId);
        $previous = $profile->credit_score;
        $updated = max(0, min(CreditScore::MAX, $previous + $delta));

        DB::transaction(function () use ($profile, $updated): void {
            $profile->forceFill(['credit_score' => $updated])->save();
        });

        $this->events->dispatch(new CreditScoreUpdated(
            organizationId: $organizationId,
            previousScore: $previous,
            newScore: $updated,
            reason: $reason,
        ));

        return $updated;
    }

    /** Explicit level change, e.g. a Compliance decision or a default. */
    public function setRiskLevel(int $organizationId, RiskLevel $level, string $reason, bool $automatic = false): RiskProfile
    {
        $profile = $this->profile($organizationId);
        $previous = $profile->risk_level;

        DB::transaction(function () use ($profile, $level): void {
            $profile->forceFill(array_merge(
                TradingLimit::defaultsFor($level),
                [
                    'risk_level' => $level->value,
                    'is_trading_allowed' => $level->canTrade() && $profile->is_trading_allowed,
                ],
            ))->save();
        });

        $this->events->dispatch(new RiskProfileChanged(
            organizationId: $organizationId,
            previousLevel: $previous->value,
            newLevel: $level->value,
            isTradingAllowed: $profile->is_trading_allowed,
            reason: $reason,
            automatic: $automatic,
        ));

        return $profile;
    }

    public function restrict(int $organizationId, string $reason): RiskProfile
    {
        $profile = $this->profile($organizationId);
        $previous = $profile->risk_level;

        DB::transaction(function () use ($profile, $reason): void {
            $profile->forceFill([
                'is_trading_allowed' => false,
                'restriction_reason' => $reason,
            ])->save();
        });

        $this->events->dispatch(new RiskProfileChanged(
            organizationId: $organizationId,
            previousLevel: $previous->value,
            newLevel: $profile->risk_level->value,
            isTradingAllowed: false,
            reason: $reason,
            automatic: true,
        ));

        return $profile;
    }

    public function profile(int $organizationId): RiskProfile
    {
        /** @var RiskProfile $profile */
        $profile = RiskProfile::query()
            ->where('organization_id', $organizationId)
            ->firstOr(fn (): RiskProfile => $this->createFor($organizationId));

        return $profile;
    }

    private function isDowngrade(RiskLevel $from, RiskLevel $to): bool
    {
        $order = [
            RiskLevel::LOW->value => 0,
            RiskLevel::MEDIUM->value => 1,
            RiskLevel::HIGH->value => 2,
            RiskLevel::CRITICAL->value => 3,
        ];

        return $order[$to->value] > $order[$from->value];
    }
}
