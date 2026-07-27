<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Domain\FlagSeverity;

/** VEL-03 — more than N trades with the same counterparty in one day. */
final class RepeatedCounterpartyRule implements AmlRule
{
    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'VEL-03';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        $counterparty = $context->otherSide();

        if (! $context->eventType->isTrade() || $counterparty === null) {
            return RuleResult::notApplicable();
        }

        $maxPerDay = (int) ($parameters['max_trades_per_day'] ?? 20);

        $trades = $this->history->tradesBetween(
            $context->organizationId,
            $counterparty,
            $context->at()->startOfDay(),
        );

        $count = count($trades) + 1;

        if ($count <= $maxPerDay) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::MEDIUM,
            'معاملات پی‌درپی با یک طرف‌حساب',
            [
                'counterparty_org_id' => $counterparty,
                'trade_count' => $count,
                'max_trades_per_day' => $maxPerDay,
            ],
        );
    }
}
