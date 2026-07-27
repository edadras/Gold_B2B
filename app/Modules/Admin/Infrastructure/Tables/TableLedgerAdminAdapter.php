<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\AccountRebuild;
use App\Modules\Admin\Contracts\LedgerAdminPort;
use App\Modules\Admin\Contracts\LedgerDiscrepancy;
use App\Modules\Admin\Contracts\ReconciliationReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation reads, straight off the ledger tables.
 *
 * This mirrors Ledger\Application\ReconciliationService, which Admin may not
 * import. Read-only by construction: there is not a single write in this class,
 * and a reconciliation that "fixed" a ledger would be worse than one that
 * reported nothing.
 *
 * Every method survives the tables being absent — the panel has to boot on an
 * installation where Ledger has not migrated yet.
 */
final class TableLedgerAdminAdapter implements LedgerAdminPort
{
    public function discrepancyCount(): int
    {
        if (! $this->tablesPresent()) {
            return 0;
        }

        return (int) DB::table('ledger_accounts as a')
            ->leftJoin('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->leftJoinSub(
                DB::table('ledger_entries')->selectRaw('account_id, SUM(amount) AS total')->groupBy('account_id'),
                's',
                's.account_id',
                '=',
                'a.id',
            )
            ->whereRaw('COALESCE(b.balance, 0) <> COALESCE(s.total, 0)')
            ->count();
    }

    public function reconcile(int $limit = 100): ReconciliationReport
    {
        if (! $this->tablesPresent()) {
            return new ReconciliationReport(0, [], [], [], [], null);
        }

        return new ReconciliationReport(
            accountsChecked: (int) DB::table('ledger_accounts')->count(),
            discrepancies: $this->discrepancies($limit),
            negativeBalances: $this->negativeBalances($limit),
            unbalancedGroups: $this->unbalancedGroups($limit),
            conservation: $this->conservation(),
            lastRunAt: $this->lastReconciliationAt(),
        );
    }

    /** @return list<LedgerDiscrepancy> */
    private function discrepancies(int $limit): array
    {
        $rows = DB::table('ledger_accounts as a')
            ->leftJoin('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->leftJoinSub(
                DB::table('ledger_entries')->selectRaw('account_id, SUM(amount) AS total')->groupBy('account_id'),
                's',
                's.account_id',
                '=',
                'a.id',
            )
            ->whereRaw('COALESCE(b.balance, 0) <> COALESCE(s.total, 0)')
            ->orderBy('a.id')
            ->limit($limit)
            ->get(['a.id', 'a.organization_id', 'a.asset_type', 'a.bucket', 'b.balance', 's.total']);

        $out = [];

        foreach ($rows as $row) {
            $stored = (int) ($row->balance ?? 0);
            $computed = (int) ($row->total ?? 0);

            $out[] = new LedgerDiscrepancy(
                accountId: (int) $row->id,
                organizationId: (int) $row->organization_id,
                assetType: (string) $row->asset_type,
                bucket: (string) $row->bucket,
                storedBalance: $stored,
                computedBalance: $computed,
                difference: $computed - $stored,
            );
        }

        return $out;
    }

    /**
     * Invariant I3. PAYABLE is the one member bucket allowed to go negative,
     * and every system account may.
     *
     * @return list<array{account_id: int, organization_id: int, asset_type: string, bucket: string, balance: int}>
     */
    private function negativeBalances(int $limit): array
    {
        $rows = DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('b.balance', '<', 0)
            ->where('a.allows_negative', '=', 0)
            ->orderBy('a.id')
            ->limit($limit)
            ->get(['a.id', 'a.organization_id', 'a.asset_type', 'a.bucket', 'b.balance']);

        return array_map(static fn (object $row): array => [
            'account_id' => (int) $row->id,
            'organization_id' => (int) $row->organization_id,
            'asset_type' => (string) $row->asset_type,
            'bucket' => (string) $row->bucket,
            'balance' => (int) $row->balance,
        ], $rows->all());
    }

    /**
     * Invariant I1 — every transaction group nets to zero per asset.
     *
     * @return list<array{transaction_group: string, asset_type: string, total: int}>
     */
    private function unbalancedGroups(int $limit): array
    {
        $rows = DB::table('ledger_entries')
            ->selectRaw('transaction_group, asset_type, SUM(amount) AS total')
            ->groupBy('transaction_group', 'asset_type')
            ->havingRaw('SUM(amount) <> 0')
            ->limit($limit)
            ->get();

        return array_map(static fn (object $row): array => [
            'transaction_group' => (string) $row->transaction_group,
            'asset_type' => (string) $row->asset_type,
            'total' => (int) $row->total,
        ], $rows->all());
    }

    /**
     * Invariant I4 — each asset sums to zero across the whole system.
     *
     * @return array<string, int>
     */
    private function conservation(): array
    {
        $rows = DB::table('ledger_entries')
            ->selectRaw('asset_type, SUM(amount) AS total')
            ->groupBy('asset_type')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->asset_type] = (int) $row->total;
        }

        return $out;
    }

    /** @return list<AccountRebuild> */
    public function rebuildForOrganization(int $organizationId): array
    {
        if (! $this->tablesPresent()) {
            return [];
        }

        $ids = DB::table('ledger_accounts')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->pluck('id');

        $out = [];

        foreach ($ids as $id) {
            $rebuild = $this->rebuildAccount((int) $id);

            if ($rebuild !== null) {
                $out[] = $rebuild;
            }
        }

        return $out;
    }

    public function rebuildAccount(int $accountId): ?AccountRebuild
    {
        if (! $this->tablesPresent()) {
            return null;
        }

        $account = DB::table('ledger_accounts')->where('id', $accountId)->first();

        if ($account === null) {
            return null;
        }

        $balance = DB::table('ledger_balances')->where('account_id', $accountId)->first();

        $rebuilt = DB::table('ledger_entries')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS entries, MAX(id) AS last_id')
            ->first();

        $name = Schema::hasTable('organizations')
            ? DB::table('organizations')->where('id', $account->organization_id)->value('display_name')
            : null;

        return new AccountRebuild(
            accountId: $accountId,
            organizationId: (int) $account->organization_id,
            organizationName: $name === null ? null : (string) $name,
            assetType: (string) $account->asset_type,
            bucket: (string) $account->bucket,
            systemAccountCode: $account->system_account_code === null
                ? null
                : (string) $account->system_account_code,
            storedBalance: (int) ($balance->balance ?? 0),
            rebuiltBalance: (int) ($rebuilt->total ?? 0),
            storedEntryCount: (int) ($balance->entry_count ?? 0),
            rebuiltEntryCount: (int) ($rebuilt->entries ?? 0),
            storedLastEntryId: isset($balance->last_entry_id) && $balance->last_entry_id !== null
                ? (int) $balance->last_entry_id
                : null,
            rebuiltLastEntryId: isset($rebuilt->last_id) && $rebuilt->last_id !== null
                ? (int) $rebuilt->last_id
                : null,
        );
    }

    /** @return array<string, int> */
    public function balancesFor(int $organizationId): array
    {
        if (! $this->tablesPresent()) {
            return [];
        }

        $rows = DB::table('ledger_accounts as a')
            ->leftJoin('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.organization_id', $organizationId)
            ->selectRaw('a.asset_type, COALESCE(SUM(b.balance), 0) AS total')
            ->groupBy('a.asset_type')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->asset_type] = (int) $row->total;
        }

        return $out;
    }

    /**
     * The nightly job writes `ledger_snapshots`; its newest row is the closest
     * thing to "last reconciled at" without a table of our own.
     */
    public function lastReconciliationAt(): ?string
    {
        if (! Schema::hasTable('ledger_snapshots')) {
            return null;
        }

        $value = DB::table('ledger_snapshots')->max('created_at');

        return $value === null ? null : (string) $value;
    }

    private function tablesPresent(): bool
    {
        return Schema::hasTable('ledger_accounts')
            && Schema::hasTable('ledger_entries')
            && Schema::hasTable('ledger_balances');
    }
}
