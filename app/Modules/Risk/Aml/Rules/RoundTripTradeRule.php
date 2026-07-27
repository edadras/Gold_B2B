<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Contracts\TradeRecord;
use App\Modules\Risk\Domain\FlagSeverity;

/**
 * PAT-01 — round trip: A sells to B and B sells the same amount back to A
 * shortly after. docs/03-domain/12-aml-compliance.md §12.4.
 *
 * The doc's sketch queries Trade directly; here the history arrives through
 * TradeHistoryReaderInterface so the rule stays inside the module boundary.
 */
final class RoundTripTradeRule implements AmlRule
{
    use WeightComparison;

    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'PAT-01';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $buyer = $context->buyerOrganizationId;
        $seller = $context->sellerOrganizationId;

        if ($buyer === null || $seller === null || $buyer === $seller) {
            return RuleResult::notApplicable();
        }

        $windowMinutes = (int) ($parameters['window_minutes'] ?? 60);
        $toleranceBps = (int) ($parameters['weight_tolerance_bps'] ?? 500);

        $since = $context->at()->subMinutes($windowMinutes);
        $candidates = $this->history->tradesBetween($buyer, $seller, $since);

        foreach ($candidates as $trade) {
            // The mirror image: the current buyer was the seller, and vice versa.
            if ($trade->buyerOrganizationId !== $seller || $trade->sellerOrganizationId !== $buyer) {
                continue;
            }

            if (! $this->weightsAreSimilar($trade->fineWeightMg, $context->fineWeightMg, $toleranceBps)) {
                continue;
            }

            return RuleResult::flag(
                FlagSeverity::HIGH,
                'معامله رفت‌وبرگشتی شناسایی شد',
                $this->contextFor($context, $trade, $windowMinutes),
            );
        }

        return RuleResult::pass();
    }

    /** @return array<string, mixed> */
    private function contextFor(AmlContext $context, TradeRecord $reverse, int $windowMinutes): array
    {
        return [
            'current_subject_id' => $context->subjectId,
            'reverse_trade_id' => $reverse->id,
            'minutes_apart' => (int) $reverse->executedAt->diffInMinutes($context->at(), absolute: true),
            'window_minutes' => $windowMinutes,
            'weight_current_mg' => $context->fineWeightMg,
            'weight_reverse_mg' => $reverse->fineWeightMg,
            'price_current' => $context->pricePerGramRial,
            'price_reverse' => $reverse->pricePerGramRial,
            'buyer_org_id' => $context->buyerOrganizationId,
            'seller_org_id' => $context->sellerOrganizationId,
        ];
    }
}
