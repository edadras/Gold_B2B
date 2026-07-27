<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Domain\FlagSeverity;

/** VOL-01 — a single trade above the reporting threshold. §12.3 category A. */
final class LargeSingleTradeRule implements AmlRule
{
    public function code(): string
    {
        return 'VOL-01';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $threshold = (int) ($parameters['threshold_mg'] ?? 10_000_000);

        if ($context->fineWeightMg <= $threshold) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::MEDIUM,
            'معامله منفرد بزرگ‌تر از آستانه شناسایی شد',
            [
                'fine_weight_mg' => $context->fineWeightMg,
                'threshold_mg' => $threshold,
                'counterparty_org_id' => $context->otherSide(),
            ],
        );
    }
}
