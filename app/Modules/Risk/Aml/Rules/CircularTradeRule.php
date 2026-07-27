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
 * PAT-02 — circular trading, A→B→C→A. docs/03-domain/12-aml-compliance.md §12.8.
 *
 * Bounded depth-first search over the recent trade graph. The window and the
 * depth cap are what keep this cheap: with maxDepth 5 the walk cannot exceed
 * five hops, and only edges inside the window exist at all.
 *
 * A cycle is reported only when every edge carries a similar weight — that is
 * the signature of value going round in a circle rather than of three members
 * legitimately trading with each other.
 */
final class CircularTradeRule implements AmlRule
{
    use WeightComparison;

    public function __construct(private readonly TradeHistoryReaderInterface $history) {}

    public function code(): string
    {
        return 'PAT-02';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->eventType->isTrade()) {
            return RuleResult::notApplicable();
        }

        $windowMinutes = (int) ($parameters['window_minutes'] ?? 120);
        $maxDepth = min((int) ($parameters['max_depth'] ?? 5), 5);
        $toleranceBps = (int) ($parameters['weight_tolerance_bps'] ?? 500);

        $edges = $this->history->recentTrades($context->at()->subMinutes($windowMinutes));

        if ($edges === []) {
            return RuleResult::pass();
        }

        $graph = $this->buildGraph($edges);
        $start = $context->organizationId;

        if (! isset($graph[$start])) {
            return RuleResult::pass();
        }

        $cycle = $this->findCycle($graph, $start, $start, [], $maxDepth, $toleranceBps);

        if ($cycle === null) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::CRITICAL,
            'معامله دایره‌ای شناسایی شد',
            [
                'path' => $this->pathOf($cycle, $start),
                'trade_ids' => array_map(static fn (TradeRecord $t): int => $t->id, $cycle),
                'weights_mg' => array_map(static fn (TradeRecord $t): int => $t->fineWeightMg, $cycle),
                'window_minutes' => $windowMinutes,
                'depth' => count($cycle),
            ],
        );
    }

    /**
     * Gold flows seller → buyer, so that is the edge direction.
     *
     * @param  list<TradeRecord>  $edges
     * @return array<int, list<TradeRecord>>
     */
    private function buildGraph(array $edges): array
    {
        $graph = [];

        foreach ($edges as $edge) {
            if ($edge->sellerOrganizationId === $edge->buyerOrganizationId) {
                continue;
            }

            $graph[$edge->sellerOrganizationId][] = $edge;
        }

        return $graph;
    }

    /**
     * @param  array<int, list<TradeRecord>>  $graph
     * @param  list<TradeRecord>  $path
     * @return list<TradeRecord>|null
     */
    private function findCycle(
        array $graph,
        int $start,
        int $current,
        array $path,
        int $maxDepth,
        int $toleranceBps,
    ): ?array {
        if (count($path) >= $maxDepth) {
            return null;
        }

        foreach ($graph[$current] ?? [] as $edge) {
            $next = $edge->buyerOrganizationId;
            $candidate = [...$path, $edge];

            if ($next === $start) {
                // A two-node "cycle" is a round trip, which PAT-01 already owns.
                if (count($candidate) < 3) {
                    continue;
                }

                $weights = array_map(static fn (TradeRecord $t): int => $t->fineWeightMg, $candidate);

                if ($this->allWeightsSimilar($weights, $toleranceBps)) {
                    return $candidate;
                }

                continue;
            }

            // Compare against the path *before* this edge: the edge we are about
            // to take necessarily lands on $next.
            if ($this->visits($path, $next)) {
                continue;
            }

            $found = $this->findCycle($graph, $start, $next, $candidate, $maxDepth, $toleranceBps);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param list<TradeRecord> $path */
    private function visits(array $path, int $organizationId): bool
    {
        foreach ($path as $edge) {
            if ($edge->buyerOrganizationId === $organizationId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<TradeRecord>  $cycle
     * @return list<int>
     */
    private function pathOf(array $cycle, int $start): array
    {
        $path = [$start];

        foreach ($cycle as $edge) {
            $path[] = $edge->buyerOrganizationId;
        }

        return $path;
    }
}
