<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Domain\FlagSeverity;

/** VEL-01 — more than N trades in an hour. §12.3 category B. */
final class TradeVelocityRule implements AmlRule
{
    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'VEL-01';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $maxPerHour = (int) ($parameters['max_trades_per_hour'] ?? 50);
        $windowMinutes = (int) ($parameters['window_minutes'] ?? 60);

        $recent = $this->history->tradesFor(
            $context->organizationId,
            $context->at()->subMinutes($windowMinutes),
        );

        // +1 counts the event being evaluated, which is not yet in history.
        $count = count($recent) + 1;

        if ($count <= $maxPerHour) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::MEDIUM,
            'تعداد معاملات در بازه کوتاه بیش از حد مجاز است',
            [
                'trade_count' => $count,
                'max_trades_per_hour' => $maxPerHour,
                'window_minutes' => $windowMinutes,
            ],
        );
    }
}
