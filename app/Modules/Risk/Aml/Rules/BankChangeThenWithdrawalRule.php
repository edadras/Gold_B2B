<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Domain\FlagSeverity;
use Carbon\CarbonImmutable;

/**
 * BEH-03 — bank account changed, then a large withdrawal shortly after.
 * §12.3 category F, the "ORG-102" example in §12.7.
 *
 * The account-change timestamp is not Risk's to query, so it arrives on the
 * context metadata as `bank_account_changed_at`; the module that owns banking
 * details supplies it when it raises the withdrawal event.
 */
final class BankChangeThenWithdrawalRule implements AmlRule
{
    public function code(): string
    {
        return 'BEH-03';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if ($context->eventType->value !== 'WITHDRAWAL') {
            return RuleResult::notApplicable();
        }

        $changedAtRaw = $context->metadata('bank_account_changed_at');

        if (! is_string($changedAtRaw) || $changedAtRaw === '') {
            return RuleResult::pass();
        }

        $windowHours = (int) ($parameters['window_hours'] ?? 24);
        $minAmountRial = (int) ($parameters['min_amount_rial'] ?? 1_000_000_000);
        $minWeightMg = (int) ($parameters['min_fine_weight_mg'] ?? 1_000_000);

        $changedAt = CarbonImmutable::parse($changedAtRaw);
        $hoursSince = $changedAt->diffInHours($context->at(), absolute: true);

        if ($hoursSince > $windowHours) {
            return RuleResult::pass();
        }

        $isLarge = $context->amountRial >= $minAmountRial
            || ($minWeightMg > 0 && $context->fineWeightMg >= $minWeightMg);

        if (! $isLarge) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::HIGH,
            'تغییر حساب بانکی و سپس برداشت بزرگ',
            [
                'bank_account_changed_at' => $changedAt->toIso8601String(),
                'hours_since_change' => (int) $hoursSince,
                'window_hours' => $windowHours,
                'amount_rial' => $context->amountRial,
                'fine_weight_mg' => $context->fineWeightMg,
            ],
        );
    }
}
