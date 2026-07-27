<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Infrastructure\Models\LedgerSnapshotModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * docs/03-domain/03-ledger.md §3.8 — daily balance checkpoints.
 *
 * A snapshot pins (balance, last_entry_id, entry_count) for an account on a
 * date, so rebuilding a balance only has to sum the entries added since. Rows
 * are computed from ledger_entries, never from the ledger_balances cache: a
 * snapshot taken from the cache would happily preserve a discrepancy.
 */
final class SnapshotService
{
    /**
     * @return int number of snapshot rows written
     */
    public function snapshotAll(?Carbon $date = null): int
    {
        $date = ($date ?? Carbon::today())->toDateString();

        $written = 0;

        // One aggregate pass over the entries, then one upsert per account.
        $totals = DB::table('ledger_entries')
            ->selectRaw('account_id, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS entries, MAX(id) AS last_id')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        foreach (DB::table('ledger_accounts')->orderBy('id')->cursor() as $account) {
            $row = $totals->get($account->id);

            LedgerSnapshotModel::query()->updateOrCreate(
                ['account_id' => (int) $account->id, 'snapshot_date' => $date],
                [
                    'balance' => (int) ($row->total ?? 0),
                    'last_entry_id' => (int) ($row->last_id ?? 0),
                    'entry_count' => (int) ($row->entries ?? 0),
                    'created_at' => now(),
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * Balance of an account rebuilt from its latest snapshot plus everything
     * appended after it — the query in §3.8.
     */
    public function balanceFromSnapshot(int $accountId): int
    {
        $snapshot = LedgerSnapshotModel::query()
            ->where('account_id', $accountId)
            ->orderByDesc('snapshot_date')
            ->first();

        if ($snapshot === null) {
            return (int) DB::table('ledger_entries')->where('account_id', $accountId)->sum('amount');
        }

        $since = (int) DB::table('ledger_entries')
            ->where('account_id', $accountId)
            ->where('id', '>', $snapshot->last_entry_id)
            ->sum('amount');

        return $snapshot->balance + $since;
    }
}
