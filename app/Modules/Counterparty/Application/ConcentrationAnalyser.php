<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Counterparty\Contracts\ConcentrationEntry;
use App\Modules\Counterparty\Contracts\ConcentrationReport;
use App\Modules\Counterparty\Domain\ConcentrationLevel;
use App\Modules\Counterparty\Domain\ExposureMetric;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Facades\DB;

/**
 * Answers "how much of what I am owed sits with a single counterparty?"
 * (docs/03-domain/10-counterparty.md §10.6).
 *
 * Only *receivables* count. Netting a payable against a receivable would
 * understate the risk being measured: if a member is owed 1,200 g by one house
 * and owes 1,150 g to another, a default by the first is still a 1,200 g hole —
 * the second debt does not disappear because the first one did.
 */
final class ConcentrationAnalyser
{
    public function analyse(
        int $organizationId,
        ExposureMetric $metric = ExposureMetric::GOLD,
    ): ConcentrationReport {
        $warningBps = $this->threshold('concentration_warning_bps', ConcentrationLevel::DEFAULT_WARNING_BPS);
        $seriousBps = $this->threshold('concentration_serious_bps', ConcentrationLevel::DEFAULT_SERIOUS_BPS);

        $column = $metric->column();

        $rows = DB::table('counterparty_relations')
            ->where('organization_id', $organizationId)
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->get(['counterparty_org_id', $column]);

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row->{$column};
        }

        $entries = [];
        foreach ($rows as $row) {
            $amount = (int) $row->{$column};
            // Integer basis points, never a float percentage (AGENT_BRIEF rule 1).
            $shareBps = $total > 0 ? IntMath::mulDivFloor($amount, 10_000, $total) : 0;

            $entries[] = new ConcentrationEntry(
                counterpartyOrgId: (int) $row->counterparty_org_id,
                amount: $amount,
                shareBps: $shareBps,
                level: ConcentrationLevel::fromShareBps($shareBps, $warningBps, $seriousBps),
            );
        }

        $topShareBps = $entries[0]->shareBps ?? 0;

        return new ConcentrationReport(
            organizationId: $organizationId,
            metric: $metric,
            totalReceivable: $total,
            entries: $entries,
            topShareBps: $topShareBps,
            level: ConcentrationLevel::fromShareBps($topShareBps, $warningBps, $seriousBps),
            warningBps: $warningBps,
            seriousBps: $seriousBps,
        );
    }

    /**
     * Every member whose book currently trips a threshold, for the nightly risk
     * digest. Returns one report per organisation, worst first.
     *
     * @return list<ConcentrationReport>
     */
    public function alarmingOrganizations(ExposureMetric $metric = ExposureMetric::GOLD): array
    {
        $organizationIds = DB::table('counterparty_relations')
            ->where($metric->column(), '>', 0)
            ->distinct()
            ->pluck('organization_id');

        $reports = [];

        foreach ($organizationIds as $organizationId) {
            $report = $this->analyse((int) $organizationId, $metric);

            if ($report->isAlarming()) {
                $reports[] = $report;
            }
        }

        usort(
            $reports,
            static fn (ConcentrationReport $a, ConcentrationReport $b): int => $b->topShareBps <=> $a->topShareBps,
        );

        return $reports;
    }

    private function threshold(string $key, int $default): int
    {
        $value = config('goldb2b.counterparty.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
