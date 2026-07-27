<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Contracts\MemberActivityReaderInterface;
use App\Modules\Risk\Contracts\MemberActivityStats;
use App\Modules\Risk\Domain\LimitIncreaseStatus;
use App\Modules\Risk\Domain\LimitType;
use App\Modules\Risk\Events\RiskProfileChanged;
use App\Modules\Risk\Infrastructure\Models\LimitIncreaseRequest;
use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * docs/03-domain/11-risk-credit.md §11.8.
 *
 * The automatic screen never approves anything — it can only reject. Everything
 * that clears the prerequisites goes to a COMPLIANCE_OFFICER, because §11.2 is
 * explicit that raising a member's standing is a human decision.
 */
final class LimitIncreaseRequestService
{
    /** Prerequisite keys, in the order §11.8 lists them. */
    public const PREREQUISITES = [
        'MIN_90_DAYS_ACTIVE',
        'MIN_50_SETTLED_TRADES',
        'ON_TIME_RATE_ABOVE_98',
        'NO_DEFAULT_IN_180_DAYS',
        'KYC_COMPLETE_AND_CURRENT',
        'CREDIT_SCORE_ABOVE_700',
    ];

    public function __construct(
        private readonly MemberActivityReaderInterface $activity,
        private readonly RiskProfileService $profiles,
        private readonly Dispatcher $events,
    ) {}

    public function request(
        int $organizationId,
        int $userId,
        LimitType $limitType,
        int $requestedValue,
        ?string $justification = null,
    ): LimitIncreaseRequest {
        $profile = $this->profiles->profile($organizationId);
        $current = $this->currentValue($profile, $limitType);

        if ($requestedValue <= $current) {
            throw new InvalidArgumentException('Requested limit must exceed the current one');
        }

        $stats = $this->activity->statsFor($organizationId);
        $failed = $this->failedPrerequisites($stats, $profile);

        return DB::transaction(fn (): LimitIncreaseRequest => LimitIncreaseRequest::query()->create([
            'organization_id' => $organizationId,
            'requested_by_user_id' => $userId,
            'limit_type' => $limitType->value,
            'current_value' => $current,
            'requested_value' => $requestedValue,
            'justification' => $justification,
            'status' => $failed === []
                ? LimitIncreaseStatus::PENDING_REVIEW->value
                : LimitIncreaseStatus::AUTO_REJECTED->value,
            'failed_prerequisites' => $failed,
            'prerequisite_snapshot' => $this->snapshot($stats, $profile),
        ]));
    }

    /**
     * The six automatic checks of §11.8.
     *
     * @return list<string> keys of the prerequisites that failed
     */
    public function failedPrerequisites(MemberActivityStats $stats, RiskProfile $profile): array
    {
        $minDays = (int) config('goldb2b.risk.limit_increase.min_days_active', 90);
        $minSettled = (int) config('goldb2b.risk.limit_increase.min_settled_trades', 50);
        $minOnTimeBps = (int) config('goldb2b.risk.limit_increase.min_on_time_bps', 9_800);
        $minScore = (int) config('goldb2b.risk.limit_increase.min_credit_score', 700);

        $onTime = $stats->onTimeRateBps();

        $failed = [];

        if ($stats->daysActive < $minDays) {
            $failed[] = 'MIN_90_DAYS_ACTIVE';
        }

        if ($stats->settledTradeCount < $minSettled) {
            $failed[] = 'MIN_50_SETTLED_TRADES';
        }

        // A null rate means no settlement history at all, which cannot clear a
        // ">98% on time" bar.
        if ($onTime === null || $onTime <= $minOnTimeBps) {
            $failed[] = 'ON_TIME_RATE_ABOVE_98';
        }

        if ($stats->defaultsLast180Days > 0) {
            $failed[] = 'NO_DEFAULT_IN_180_DAYS';
        }

        if (! $stats->kycComplete()) {
            $failed[] = 'KYC_COMPLETE_AND_CURRENT';
        }

        if ($profile->credit_score <= $minScore) {
            $failed[] = 'CREDIT_SCORE_ABOVE_700';
        }

        return $failed;
    }

    public function approve(LimitIncreaseRequest $request, int $reviewerUserId, string $notes): LimitIncreaseRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $reviewerUserId, $notes): LimitIncreaseRequest {
            $this->applyToProfile($request);

            $request->forceFill([
                'status' => LimitIncreaseStatus::APPROVED->value,
                'reviewed_by_user_id' => $reviewerUserId,
                'reviewed_at' => CarbonImmutable::now(),
                'decision_notes' => $notes,
                'next_review_at' => CarbonImmutable::now()->addDays(90),
            ])->save();

            return $request;
        });
    }

    /** "تأیید مشروط" — granted, but only once the collateral is in place. */
    public function approveWithCollateral(
        LimitIncreaseRequest $request,
        int $reviewerUserId,
        string $notes,
        int $requiredCollateralRial,
    ): LimitIncreaseRequest {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $reviewerUserId, $notes, $requiredCollateralRial): LimitIncreaseRequest {
            $request->forceFill([
                'status' => LimitIncreaseStatus::APPROVED_WITH_COLLATERAL->value,
                'reviewed_by_user_id' => $reviewerUserId,
                'reviewed_at' => CarbonImmutable::now(),
                'decision_notes' => $notes,
                'required_collateral_rial' => $requiredCollateralRial,
                'next_review_at' => CarbonImmutable::now()->addDays(90),
            ])->save();

            return $request;
        });
    }

    public function reject(LimitIncreaseRequest $request, int $reviewerUserId, string $notes): LimitIncreaseRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $reviewerUserId, $notes): LimitIncreaseRequest {
            $request->forceFill([
                'status' => LimitIncreaseStatus::REJECTED->value,
                'reviewed_by_user_id' => $reviewerUserId,
                'reviewed_at' => CarbonImmutable::now(),
                'decision_notes' => $notes,
            ])->save();

            return $request;
        });
    }

    private function assertPending(LimitIncreaseRequest $request): void
    {
        if ($request->status !== LimitIncreaseStatus::PENDING_REVIEW) {
            throw new InvalidStateTransitionException(
                'LimitIncreaseRequest',
                $request->status->value,
                LimitIncreaseStatus::APPROVED->value,
            );
        }
    }

    private function applyToProfile(LimitIncreaseRequest $request): void
    {
        $profile = $this->profiles->profile($request->organization_id);
        $column = $this->columnFor($request->limit_type);

        $previousLevel = $profile->risk_level->value;

        $profile->forceFill([
            $column => $request->requested_value,
            'next_review_at' => CarbonImmutable::now()->addDays(90),
        ])->save();

        $this->events->dispatch(new RiskProfileChanged(
            organizationId: $request->organization_id,
            previousLevel: $previousLevel,
            newLevel: $profile->risk_level->value,
            isTradingAllowed: $profile->is_trading_allowed,
            reason: 'LIMIT_INCREASE_APPROVED',
            automatic: false,
            changes: [$column => $request->requested_value],
        ));
    }

    private function currentValue(RiskProfile $profile, LimitType $limitType): int
    {
        return (int) $profile->{$this->columnFor($limitType)};
    }

    private function columnFor(LimitType $limitType): string
    {
        return match ($limitType) {
            LimitType::PER_ORDER, LimitType::USER_ORDER => 'max_order_mg',
            LimitType::DAILY_VOLUME, LimitType::USER_DAILY, LimitType::COUNTERPARTY => 'max_daily_volume_mg',
            LimitType::OPEN_ORDERS => 'max_open_orders',
            LimitType::OPEN_EXPOSURE => 'max_open_exposure_mg',
            LimitType::OPEN_EXPOSURE_RIAL => 'max_open_exposure_rial',
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(MemberActivityStats $stats, RiskProfile $profile): array
    {
        return [
            'days_active' => $stats->daysActive,
            'settled_trade_count' => $stats->settledTradeCount,
            'on_time_rate_bps' => $stats->onTimeRateBps(),
            'defaults_last_180_days' => $stats->defaultsLast180Days,
            'kyc_complete' => $stats->kycComplete(),
            'credit_score' => $profile->credit_score,
        ];
    }
}
