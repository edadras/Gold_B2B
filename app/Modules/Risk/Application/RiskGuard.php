<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use App\Modules\Risk\Contracts\RiskDecision;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\Contracts\TradeIntent;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Risk\Domain\ExposureCalculator;
use App\Modules\Risk\Domain\LimitType;
use App\Modules\Risk\Events\LimitExceeded;
use App\Modules\Risk\Exceptions\LicenseExpiredException;
use App\Modules\Risk\Exceptions\OrganizationNotActiveException;
use App\Modules\Risk\Exceptions\OutsideTradingHoursException;
use App\Modules\Risk\Exceptions\RiskProfileNotFoundException;
use App\Modules\Risk\Exceptions\SettlementTypeNotAllowedException;
use App\Modules\Risk\Exceptions\TradingNotAllowedException;
use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use App\Modules\Risk\Infrastructure\Models\UserLimit;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Exceptions\LimitExceededException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Pre-trade risk check — docs/03-domain/11-risk-credit.md §11.4.
 *
 * The eleven checks run in the order the document lists them, and that order is
 * load-bearing: cheap in-memory predicates first, then the Redis counter, then
 * the aggregate queries that reach into Trading and Settlement. A member who
 * fails several checks is told about the cheapest one, and the expensive
 * queries never run.
 *
 *   1  trading permission        in memory
 *   2  organization status       cached read
 *   3  licence validity          cached read
 *   4  per-order ceiling         in memory
 *   5  daily volume              Redis
 *   6  open order count          aggregate query
 *   7  open exposure             aggregate query
 *   8  settlement type           in memory
 *   9  counterparty ceiling      aggregate query, OTC only
 *  10  user ceiling              indexed row + Redis
 *  11  trading hours             in memory
 *
 * Checks 8 and 11 sit later than their cost would suggest because §11.4 fixes
 * the sequence and the review order matters more than a microsecond.
 */
final class RiskGuard implements RiskGuardInterface
{
    public function __construct(
        private readonly OrganizationStatusReaderInterface $organizations,
        private readonly TradingExposureReaderInterface $exposures,
        private readonly DailyCounters $counters,
        private readonly ExposureCalculator $exposureCalculator,
        private readonly Dispatcher $events,
    ) {}

    public function assertTradeAllowed(TradeIntent $intent): void
    {
        $profile = $this->profileFor($intent->organizationId);

        // 1) overall permission
        if (! $profile->is_trading_allowed) {
            throw new TradingNotAllowedException($profile->restriction_reason);
        }

        // 2) membership status
        $status = $this->organizations->statusOf($intent->organizationId);
        if ($status !== 'ACTIVE') {
            throw new OrganizationNotActiveException($status);
        }

        // 3) licence validity
        $licenseExpiresAt = $this->organizations->licenseExpiresAt($intent->organizationId);
        if ($licenseExpiresAt !== null && $licenseExpiresAt->isPast()) {
            throw new LicenseExpiredException($licenseExpiresAt->toIso8601String());
        }

        // 4) per-order ceiling
        if ($intent->fineWeightMg > $profile->max_order_mg) {
            $this->reject(
                $intent,
                LimitType::PER_ORDER,
                $intent->fineWeightMg,
                $profile->max_order_mg,
            );
        }

        // 5) daily volume — first call that leaves the process
        $todayVolume = $this->counters->dailyVolumeMg($intent->organizationId);
        $projected = IntMath::add($todayVolume, $intent->fineWeightMg);
        if ($projected > $profile->max_daily_volume_mg) {
            $this->reject($intent, LimitType::DAILY_VOLUME, $projected, $profile->max_daily_volume_mg);
        }

        // 6) concurrent open orders
        $openCount = $this->exposures->openOrderCount($intent->organizationId);
        if ($openCount >= $profile->max_open_orders) {
            $this->reject($intent, LimitType::OPEN_ORDERS, $openCount + 1, $profile->max_open_orders);
        }

        // 7) open exposure (F18)
        $exposure = $this->exposureCalculator->combine(
            settlementGoldMg: $this->exposures->openGoldExposureMg($intent->organizationId),
            openOrderGoldMg: 0,
            settlementRial: $this->exposures->openRialExposure($intent->organizationId),
            openOrderRial: 0,
        );

        $projectedGold = $exposure->withAdditionalGold($intent->fineWeightMg);
        if ($projectedGold > $profile->max_open_exposure_mg) {
            $this->reject($intent, LimitType::OPEN_EXPOSURE, $projectedGold, $profile->max_open_exposure_mg);
        }

        $projectedRial = $exposure->withAdditionalRial($intent->grossValue()->amount);
        if ($profile->max_open_exposure_rial > 0 && $projectedRial > $profile->max_open_exposure_rial) {
            $this->reject(
                $intent,
                LimitType::OPEN_EXPOSURE_RIAL,
                $projectedRial,
                $profile->max_open_exposure_rial,
            );
        }

        // 8) settlement type
        if (! $profile->allowsSettlementType($intent->settlementType)) {
            throw new SettlementTypeNotAllowedException(
                $intent->settlementType,
                $profile->allowed_settlement_types,
            );
        }

        // 9) counterparty ceiling — OTC only
        if ($intent->counterpartyOrgId !== null) {
            $this->assertCounterpartyLimit($intent, $profile);
        }

        // 10) per-user ceiling
        $this->assertUserLimit($intent);

        // 11) trading hours
        $this->assertWithinTradingHours($intent);
    }

    public function evaluate(TradeIntent $intent): RiskDecision
    {
        try {
            $this->assertTradeAllowed($intent);
        } catch (LimitExceededException $e) {
            return RiskDecision::rejected(
                reasonCode: $e->errorCode(),
                message: $e->userMessage(),
                limitType: LimitType::tryFrom($e->limitType),
                requested: $e->requested,
                limit: $e->limit,
                details: $e->details(),
            );
        } catch (DomainException $e) {
            return RiskDecision::rejected(
                reasonCode: $e->errorCode(),
                message: $e->userMessage(),
                details: $e->details(),
            );
        }

        return RiskDecision::allowed();
    }

    private function profileFor(int $organizationId): RiskProfile
    {
        /** @var RiskProfile|null $profile */
        $profile = RiskProfile::query()->where('organization_id', $organizationId)->first();

        if ($profile === null) {
            throw new RiskProfileNotFoundException($organizationId);
        }

        return $profile;
    }

    /**
     * §11.4 check 9. The document names the check but leaves the ceiling to
     * policy, so a member may put at most a configured share of their daily
     * allowance through any single counterparty — concentration is exactly what
     * AML rule CPT-02 is worried about.
     */
    private function assertCounterpartyLimit(TradeIntent $intent, RiskProfile $profile): void
    {
        if ($intent->counterpartyOrgId === $intent->organizationId) {
            throw new OperationNotPermittedException('SELF_TRADE');
        }

        $shareBps = (int) config('goldb2b.risk.counterparty_daily_share_bps', 5_000);
        $limit = IntMath::mulDivFloor($profile->max_daily_volume_mg, $shareBps, 10_000);

        if ($limit <= 0) {
            return;
        }

        $used = $this->exposures->counterpartyDailyVolumeMg(
            $intent->organizationId,
            $intent->counterpartyOrgId,
        );

        $projected = IntMath::add($used, $intent->fineWeightMg);

        if ($projected > $limit) {
            $this->reject($intent, LimitType::COUNTERPARTY, $projected, $limit);
        }
    }

    /** §11.4 check 10. Absent or inactive rows mean the member-level ceiling applies. */
    private function assertUserLimit(TradeIntent $intent): void
    {
        /** @var UserLimit|null $limit */
        $limit = UserLimit::query()
            ->active()
            ->where('user_id', $intent->userId)
            ->first();

        if ($limit === null) {
            return;
        }

        if ($intent->fineWeightMg > $limit->max_order_mg) {
            $this->reject($intent, LimitType::USER_ORDER, $intent->fineWeightMg, $limit->max_order_mg);
        }

        $used = $this->counters->userDailyVolumeMg($intent->userId);
        $projected = IntMath::add($used, $intent->fineWeightMg);

        if ($projected > $limit->max_daily_volume_mg) {
            $this->reject($intent, LimitType::USER_DAILY, $projected, $limit->max_daily_volume_mg);
        }
    }

    /** §11.4 check 11, against the market window in config('goldb2b.market'). */
    private function assertWithinTradingHours(TradeIntent $intent): void
    {
        if (! (bool) config('goldb2b.risk.enforce_trading_hours', true)) {
            return;
        }

        $timezone = (string) config('goldb2b.market.timezone', 'Asia/Tehran');
        $open = (string) config('goldb2b.market.open_time', '09:00');
        $close = (string) config('goldb2b.market.close_time', '17:30');

        $at = $intent->at()->setTimezone($timezone);
        $current = $at->format('H:i');

        if ($current < $open || $current > $close) {
            throw new OutsideTradingHoursException($current, $open, $close);
        }
    }

    /**
     * @throws LimitExceededException always
     */
    private function reject(TradeIntent $intent, LimitType $type, int $requested, int $limit): never
    {
        // Rule 3: nothing here runs inside a transaction, so the event is safe.
        $this->events->dispatch(new LimitExceeded(
            organizationId: $intent->organizationId,
            userId: $intent->userId,
            limitType: $type->value,
            requested: $requested,
            limit: $limit,
        ));

        throw new LimitExceededException($type->value, $requested, $limit);
    }
}
