<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Risk\Domain\CollateralCoverage;
use App\Modules\Risk\Domain\CoverageAssessment;
use App\Modules\Risk\Domain\ExposureCalculator;
use App\Modules\Risk\Events\MarginCallIssued;
use App\Modules\Risk\Infrastructure\Models\Collateral;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Collateral valuation and margin calls. docs/03-domain/11-risk-credit.md §11.6–§11.7.
 *
 * Gold collateral is marked to the current price through Pricing's
 * PriceReaderInterface, so a price move alone can trigger a call. §11.7 asks
 * for the check on every price update, throttled to five minutes — the throttle
 * lives here.
 */
final class CollateralService
{
    public function __construct(
        private readonly CollateralCoverage $coverage,
        private readonly ExposureCalculator $exposures,
        private readonly TradingExposureReaderInterface $exposureReader,
        private readonly PriceReaderInterface $prices,
        private readonly Dispatcher $events,
        private readonly CacheRepository $cache,
    ) {}

    /** Accepted (post-haircut) value of a member's active pledges, in rial. */
    public function collateralValueRial(int $organizationId, ?PricePerFineGram $goldPrice = null): int
    {
        /** @var list<Collateral> $pledges */
        $pledges = Collateral::query()
            ->counting()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get()
            ->all();

        $total = 0;

        foreach ($pledges as $pledge) {
            $nominal = $pledge->type->isPriceSensitive() && $pledge->fine_weight_mg !== null && $goldPrice !== null
                ? IntMath::mulDivFloor($pledge->fine_weight_mg, $goldPrice->rial, Weight::MG_PER_GRAM)
                : $pledge->nominal_value_rial;

            $total = IntMath::add(
                $total,
                $this->coverage->acceptedValue($nominal, $pledge->acceptance_factor_bps),
            );
        }

        return $total;
    }

    public function assess(int $organizationId, int $instrumentId = 1): CoverageAssessment
    {
        $goldPrice = $this->prices->lastPrice($instrumentId)
            ?? $this->prices->referencePrice($instrumentId);

        $exposure = $this->exposures->combine(
            settlementGoldMg: $this->exposureReader->openGoldExposureMg($organizationId),
            openOrderGoldMg: 0,
            settlementRial: $this->exposureReader->openRialExposure($organizationId),
            openOrderRial: 0,
        );

        $exposureValue = $this->exposures->valueInRial(
            $exposure,
            $goldPrice ?? PricePerFineGram::zero(),
        );

        return $this->coverage->evaluate(
            $this->collateralValueRial($organizationId, $goldPrice),
            $exposureValue,
        );
    }

    /**
     * Assesses and emits MarginCallIssued when the band demands it, at most once
     * per throttle window per member.
     */
    public function review(int $organizationId, int $instrumentId = 1): CoverageAssessment
    {
        $assessment = $this->assess($organizationId, $instrumentId);

        if (! $assessment->requiresMarginCall() || $this->isThrottled($organizationId)) {
            return $assessment;
        }

        $this->markThrottled($organizationId);

        $this->events->dispatch(new MarginCallIssued(
            organizationId: $organizationId,
            coverageRatioBps: $assessment->ratioBps ?? 0,
            collateralValueRial: $assessment->collateralValueRial,
            exposureValueRial: $assessment->exposureValueRial,
            shortfallRial: $assessment->shortfallToHealthyRial(),
            deadlineHours: $assessment->status->topUpDeadlineHours(),
            status: $assessment->status->value,
        ));

        return $assessment;
    }

    private function throttleKey(int $organizationId): string
    {
        return "risk:margin-call:{$organizationId}";
    }

    private function isThrottled(int $organizationId): bool
    {
        return $this->cache->has($this->throttleKey($organizationId));
    }

    private function markThrottled(int $organizationId): void
    {
        $this->cache->put(
            $this->throttleKey($organizationId),
            true,
            (int) config('goldb2b.risk.margin_call_throttle_seconds', 300),
        );
    }
}
