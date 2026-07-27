<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Infrastructure;

use App\Modules\Reputation\Contracts\CounterpartyCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counts distinct counterparties from `counterparty_relations`, as §14.6
 * prescribes.
 *
 * Read-only, and by table rather than by model: the Counterparty module owns
 * that Eloquent class and Reputation may not import it. If the table is not
 * present in this deployment slice the count is zero, which keeps the tier
 * rules conservative — a missing input can only ever hold a member back, never
 * hand out a badge that was not earned.
 *
 * Only relations that actually traded count. A row created by setting a credit
 * limit on a member you have never dealt with is not a business relationship,
 * and counting it would hand back the very loophole §14.8 closes.
 */
final class RelationTableCounterpartyCounter implements CounterpartyCounter
{
    private const TABLE = 'counterparty_relations';

    public function distinctCounterparties(int $organizationId): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        return DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('total_trade_count', '>', 0)
            ->distinct()
            ->count('counterparty_org_id');
    }

    /** @return array<int, int> */
    public function allDistinctCounterparties(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $rows = DB::table(self::TABLE)
            ->where('total_trade_count', '>', 0)
            ->groupBy('organization_id')
            ->selectRaw('organization_id, COUNT(DISTINCT counterparty_org_id) AS c')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->organization_id] = (int) $row->c;
        }

        return $counts;
    }
}
