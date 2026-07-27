<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Domain\FlagSeverity;

/**
 * PAT-03 — buying and selling the same quantity within minutes at no profit.
 * §12.3 category C.
 *
 * Unlike PAT-01 the counterparty need not be the same; what is suspicious is
 * the member passing gold straight through without taking a position.
 */
final class ImmediateFlipRule implements AmlRule
{
    use WeightComparison;

    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'PAT-03';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $org = $context->organizationId;
        $isBuying = $context->buyerOrganizationId === $org;
        $isSelling = $context->sellerOrganizationId === $org;

        if (! $isBuying && ! $isSelling) {
            return RuleResult::notApplicable();
        }

        $windowMinutes = (int) ($parameters['window_minutes'] ?? 10);
        $toleranceBps = (int) ($parameters['weight_tolerance_bps'] ?? 500);
        $maxProfitBps = (int) ($parameters['max_profit_bps'] ?? 50);

        $trades = $this->history->tradesFor($org, $context->at()->subMinutes($windowMinutes));

        foreach ($trades as $trade) {
            $oppositeSide = $isBuying
                ? $trade->sellerOrganizationId === $org
                : $trade->buyerOrganizationId === $org;

            if (! $oppositeSide) {
                continue;
            }

            if (! $this->weightsAreSimilar($trade->fineWeightMg, $context->fineWeightMg, $toleranceBps)) {
                continue;
            }

            if ($this->profitBps($context->pricePerGramRial, $trade->pricePerGramRial) > $maxProfitBps) {
                continue;
            }

            return RuleResult::flag(
                FlagSeverity::HIGH,
                'خرید و فروش فوری همان مقدار بدون سود',
                [
                    'paired_trade_id' => $trade->id,
                    'minutes_apart' => (int) $trade->executedAt->diffInMinutes($context->at(), absolute: true),
                    'weight_current_mg' => $context->fineWeightMg,
                    'weight_paired_mg' => $trade->fineWeightMg,
                    'price_current' => $context->pricePerGramRial,
                    'price_paired' => $trade->pricePerGramRial,
                    'window_minutes' => $windowMinutes,
                ],
            );
        }

        return RuleResult::pass();
    }

    private function profitBps(int $priceA, int $priceB): int
    {
        if ($priceA <= 0 || $priceB <= 0) {
            return 0;
        }

        return intdiv(abs($priceA - $priceB) * 10_000, max($priceA, $priceB));
    }
}
