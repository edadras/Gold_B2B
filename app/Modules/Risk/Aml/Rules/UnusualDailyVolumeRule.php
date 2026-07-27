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
 * VOL-02 — today's volume is a multiple of the member's own 30-day baseline.
 *
 * Relative to the member, not to an absolute kilo figure: §12.9 point 1. A
 * member with no meaningful baseline yet is left alone (§12.9 point 2, the
 * 30-day learning period).
 */
final class UnusualDailyVolumeRule implements AmlRule
{
    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'VOL-02';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $multiplier = (int) ($parameters['multiplier'] ?? 5);
        $lookbackDays = (int) ($parameters['lookback_days'] ?? 30);
        $minBaselineMg = (int) ($parameters['min_baseline_mg'] ?? 100_000);

        $baseline = $this->history->averageDailyVolumeMg($context->organizationId, $lookbackDays);

        if ($baseline < $minBaselineMg) {
            return RuleResult::pass();
        }

        $today = IntMath::add(
            $this->history->dailyVolumeMg($context->organizationId, $context->at()),
            $context->fineWeightMg,
        );

        $ceiling = IntMath::mul($baseline, $multiplier);

        if ($today <= $ceiling) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::HIGH,
            'حجم روزانه بسیار بیشتر از میانگین خود عضو است',
            [
                'today_volume_mg' => $today,
                'baseline_daily_mg' => $baseline,
                'multiplier' => $multiplier,
                'lookback_days' => $lookbackDays,
            ],
        );
    }
}
