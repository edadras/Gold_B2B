<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Shared\Support\IntMath;

/**
 * STR-01 — several trades in one day each sitting just below the reporting
 * threshold. §12.3 category D: "≥ 5 trades, each at 90–99% of the threshold".
 *
 * Trades comfortably below the threshold are not evidence of anything; it is
 * the clustering right underneath it that gives structuring away.
 */
final class StructuringRule implements AmlRule
{
    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'STR-01';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $threshold = (int) ($parameters['threshold_mg'] ?? 10_000_000);
        $minCount = (int) ($parameters['min_count'] ?? 5);
        $lowerBps = (int) ($parameters['lower_bound_bps'] ?? 9_000);
        $upperBps = (int) ($parameters['upper_bound_bps'] ?? 9_900);

        $lower = IntMath::mulDivFloor($threshold, $lowerBps, 10_000);
        $upper = IntMath::mulDivFloor($threshold, $upperBps, 10_000);

        $weights = [];

        if ($this->inBand($context->fineWeightMg, $lower, $upper)) {
            $weights[] = $context->fineWeightMg;
        }

        foreach ($this->history->tradesFor($context->organizationId, $context->at()->startOfDay()) as $trade) {
            if ($this->inBand($trade->fineWeightMg, $lower, $upper)) {
                $weights[] = $trade->fineWeightMg;
            }
        }

        if (count($weights) < $minCount) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::HIGH,
            'الگوی تقسیم‌بندی معاملات زیر آستانه',
            [
                'threshold_mg' => $threshold,
                'band_mg' => [$lower, $upper],
                'trade_count' => count($weights),
                'min_count' => $minCount,
                'weights_mg' => $weights,
            ],
        );
    }

    private function inBand(int $weightMg, int $lower, int $upper): bool
    {
        return $weightMg >= $lower && $weightMg <= $upper;
    }
}
