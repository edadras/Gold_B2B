<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Application;

use App\Modules\Reputation\Contracts\CounterpartyCounter;
use App\Modules\Reputation\Infrastructure\ReputationStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly pass of §14.6: recompute what cannot be incremented, roll up the
 * period tables, then let the tier evaluator promote whoever now qualifies.
 */
final class RecomputeService
{
    public function __construct(
        private readonly CounterpartyCounter $counterparties,
        private readonly TierEvaluator $tiers,
    ) {}

    /**
     * `distinct_counterparties` is a set cardinality, not a running total, so it
     * is rebuilt wholesale rather than nudged. Members whose count dropped to
     * zero are reset too — leaving a stale figure behind would keep a tier
     * requirement satisfied by history that no longer exists.
     *
     * @return int number of rows whose count changed
     */
    public function recomputeDistinctCounterparties(): int
    {
        $counts = $this->counterparties->allDistinctCounterparties();
        $changed = 0;

        ReputationStat::query()
            ->orderBy('organization_id')
            ->chunkById(500, function ($stats) use ($counts, &$changed): void {
                foreach ($stats as $stat) {
                    $computed = $counts[$stat->organization_id] ?? 0;

                    if ($computed === $stat->distinct_counterparties) {
                        continue;
                    }

                    DB::table('reputation_stats')
                        ->where('organization_id', $stat->organization_id)
                        ->update([
                            'distinct_counterparties' => $computed,
                            'updated_at' => Carbon::now()->toDateTimeString(),
                        ]);

                    $changed++;
                }
            }, 'organization_id');

        return $changed;
    }

    /**
     * Fold DAY rows into WEEK and MONTH rows for the trend chart.
     *
     * @return int number of period rows written
     */
    public function rollUpPeriods(?Carbon $upTo = null): int
    {
        $upTo = $upTo ?? Carbon::now();
        $written = 0;

        foreach (['WEEK' => 'startOfWeek', 'MONTH' => 'startOfMonth'] as $type => $anchor) {
            $rows = DB::table('reputation_periods')
                ->where('period_type', 'DAY')
                ->where('period_start', '<=', $upTo->toDateString())
                ->get();

            $buckets = [];

            foreach ($rows as $row) {
                $start = Carbon::parse($row->period_start)->{$anchor}()->toDateString();
                $key = $row->organization_id.'|'.$start;

                $buckets[$key] ??= [
                    'organization_id' => (int) $row->organization_id,
                    'period_start' => $start,
                    'trades' => 0,
                    'volume_mg' => 0,
                    'settlements_total' => 0,
                    'settlements_on_time' => 0,
                    'disputes' => 0,
                ];

                $buckets[$key]['trades'] += (int) $row->trades;
                $buckets[$key]['volume_mg'] += (int) $row->volume_mg;
                $buckets[$key]['settlements_total'] += (int) $row->settlements_total;
                $buckets[$key]['settlements_on_time'] += (int) $row->settlements_on_time;
                $buckets[$key]['disputes'] += (int) $row->disputes;
            }

            foreach ($buckets as $bucket) {
                DB::table('reputation_periods')->updateOrInsert(
                    [
                        'organization_id' => $bucket['organization_id'],
                        'period_type' => $type,
                        'period_start' => $bucket['period_start'],
                    ],
                    [
                        'trades' => $bucket['trades'],
                        'volume_mg' => $bucket['volume_mg'],
                        'settlements_total' => $bucket['settlements_total'],
                        'settlements_on_time' => $bucket['settlements_on_time'],
                        'disputes' => $bucket['disputes'],
                        'on_time_rate_bps' => $bucket['settlements_total'] > 0
                            ? intdiv($bucket['settlements_on_time'] * 10_000, $bucket['settlements_total'])
                            : null,
                    ],
                );

                $written++;
            }
        }

        return $written;
    }

    /**
     * The promotion pass on its own — automatic, upward only.
     *
     * @return list<int> organisation ids promoted
     */
    public function promotedByTierPass(): array
    {
        return $this->tiers->promoteAll();
    }

    /**
     * @return array{distinct_changed: int, periods_written: int, promoted: list<int>}
     */
    public function runAll(): array
    {
        $changed = $this->recomputeDistinctCounterparties();
        $periods = $this->rollUpPeriods();
        // Tiers are evaluated last: a promotion may hinge on the distinct
        // counterparty figure that was just corrected.
        $promoted = $this->tiers->promoteAll();

        return [
            'distinct_changed' => $changed,
            'periods_written' => $periods,
            'promoted' => $promoted,
        ];
    }
}
