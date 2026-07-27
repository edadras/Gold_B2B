<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\HashChainBuilder;
use App\Modules\Ledger\Events\BalanceDiscrepancyDetected;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * docs/03-domain/03-ledger.md §3.7 — the nightly safety net.
 *
 * Verifies, in order:
 *   I2  cached balance == Σ(entries) for every account
 *   I3  non-negative balances outside PAYABLE and the system accounts
 *   I1  every transaction_group sums to zero per asset
 *   I4  every asset sums to zero across the whole system
 *   I9  balance_after agrees with the running sum
 *   I10 the per-account hash chain is intact
 *
 * Reads only. When something is wrong it emits BalanceDiscrepancyDetected and
 * lets a listener decide whether to freeze the organisation — a reconciliation
 * job must never "fix" a ledger by writing to it.
 */
final class ReconciliationService
{
    public function __construct(private readonly HashChainBuilder $hashChain) {}

    /**
     * @param  ?array<int, int>  $accountIds  null reconciles everything
     * @return array<string, mixed> report
     */
    public function reconcile(?array $accountIds = null, bool $verifyHashes = false): array
    {
        $discrepancies = $this->checkBalances($accountIds);
        $negatives = $this->checkNonNegative($accountIds);
        $unbalancedGroups = $this->unbalancedGroups();
        $conservation = $this->conservationByAsset();
        $brokenChains = $verifyHashes ? $this->verifyHashChains($accountIds) : [];

        foreach ($discrepancies as $discrepancy) {
            event(new BalanceDiscrepancyDetected(
                accountId: $discrepancy['account_id'],
                organizationId: $discrepancy['organization_id'],
                assetType: $discrepancy['asset_type'],
                bucket: $discrepancy['bucket'],
                storedBalance: $discrepancy['stored'],
                computedBalance: $discrepancy['computed'],
                difference: $discrepancy['difference'],
            ));
        }

        foreach ($conservation as $asset => $sum) {
            if ($sum !== 0) {
                Log::critical('Ledger does not balance system-wide', [
                    'asset_type' => $asset,
                    'sum' => $sum,
                ]);
            }
        }

        return [
            'accounts_checked' => $this->accountCount($accountIds),
            'discrepancies' => $discrepancies,
            'negative_balances' => $negatives,
            'unbalanced_groups' => $unbalancedGroups,
            'conservation' => $conservation,
            'broken_hash_chains' => $brokenChains,
            'healthy' => $discrepancies === []
                && $negatives === []
                && $unbalancedGroups === []
                && $brokenChains === []
                && $conservation === array_fill_keys(array_keys($conservation), 0),
        ];
    }

    /**
     * Invariant I2 — stored balance vs Σ(entries), computed in one join so a
     * quarter of a million accounts do not become a quarter of a million queries.
     *
     * @param  ?array<int, int>  $accountIds
     * @return array<int, array{account_id: int, organization_id: int, asset_type: string, bucket: string, stored: int, computed: int, difference: int}>
     */
    public function checkBalances(?array $accountIds = null): array
    {
        $sums = DB::table('ledger_entries')
            ->selectRaw('account_id, SUM(amount) AS total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $query = DB::table('ledger_accounts as a')
            ->leftJoin('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->select('a.id', 'a.organization_id', 'a.asset_type', 'a.bucket', 'b.balance');

        if ($accountIds !== null) {
            $query->whereIn('a.id', $accountIds);
        }

        $out = [];

        foreach ($query->orderBy('a.id')->cursor() as $account) {
            $stored = (int) ($account->balance ?? 0);
            $computed = (int) ($sums[$account->id] ?? 0);

            if ($stored !== $computed) {
                $out[] = [
                    'account_id' => (int) $account->id,
                    'organization_id' => (int) $account->organization_id,
                    'asset_type' => (string) $account->asset_type,
                    'bucket' => (string) $account->bucket,
                    'stored' => $stored,
                    'computed' => $computed,
                    'difference' => $computed - $stored,
                ];
            }
        }

        return $out;
    }

    /**
     * Invariant I3 — AVAILABLE / RESERVED / IN_SETTLEMENT / IN_DISPUTE never
     * go below zero. Accounts flagged allows_negative are exempt by design.
     *
     * @param  ?array<int, int>  $accountIds
     * @return array<int, array{account_id: int, organization_id: int, bucket: string, balance: int}>
     */
    public function checkNonNegative(?array $accountIds = null): array
    {
        $query = DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.allows_negative', false)
            ->where('b.balance', '<', 0)
            ->select('a.id', 'a.organization_id', 'a.bucket', 'b.balance');

        if ($accountIds !== null) {
            $query->whereIn('a.id', $accountIds);
        }

        return $query->get()->map(static fn (object $row): array => [
            'account_id' => (int) $row->id,
            'organization_id' => (int) $row->organization_id,
            'bucket' => (string) $row->bucket,
            'balance' => (int) $row->balance,
        ])->all();
    }

    /**
     * Invariant I1, checked after the fact — the net that catches a group whose
     * assertGroupBalances() was somehow bypassed (worked example 7).
     *
     * @return array<int, array{transaction_group: string, asset_type: string, sum: int}>
     */
    public function unbalancedGroups(): array
    {
        return DB::table('ledger_entries')
            ->select('transaction_group', 'asset_type')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('transaction_group', 'asset_type')
            ->havingRaw('SUM(amount) <> 0')
            ->get()
            ->map(static fn (object $row): array => [
                'transaction_group' => (string) $row->transaction_group,
                'asset_type' => (string) $row->asset_type,
                'sum' => (int) $row->total,
            ])->all();
    }

    /**
     * Invariant I4 — Σ(all entries of an asset) across the entire system is 0.
     * Member positives are offset by the system accounts they came through.
     *
     * @return array<string, int>
     */
    public function conservationByAsset(): array
    {
        $sums = DB::table('ledger_entries')
            ->selectRaw('asset_type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('asset_type')
            ->pluck('total', 'asset_type');

        $out = [];

        foreach (AssetType::cases() as $asset) {
            $out[$asset->value] = (int) ($sums[$asset->value] ?? 0);
        }

        return $out;
    }

    /**
     * Invariant I9 — balance_after of each entry equals the running sum on its
     * account up to and including that entry.
     *
     * @return array<int, array{entry_id: int, account_id: int, stored: int, expected: int}>
     */
    public function checkRunningBalances(?array $accountIds = null): array
    {
        $query = DB::table('ledger_entries')
            ->select('id', 'account_id', 'amount', 'balance_after')
            ->orderBy('account_id')
            ->orderBy('id');

        if ($accountIds !== null) {
            $query->whereIn('account_id', $accountIds);
        }

        $out = [];
        $currentAccount = null;
        $running = 0;

        foreach ($query->cursor() as $row) {
            if ($currentAccount !== $row->account_id) {
                $currentAccount = $row->account_id;
                $running = 0;
            }

            $running += (int) $row->amount;

            if ($running !== (int) $row->balance_after) {
                $out[] = [
                    'entry_id' => (int) $row->id,
                    'account_id' => (int) $row->account_id,
                    'stored' => (int) $row->balance_after,
                    'expected' => $running,
                ];
            }
        }

        return $out;
    }

    /**
     * Invariant I10 — recompute every account's hash chain from its rows.
     *
     * @param  ?array<int, int>  $accountIds
     * @return array<int, array{account_id: int, entry_ids: array<int, int>}>
     */
    public function verifyHashChains(?array $accountIds = null): array
    {
        $query = LedgerAccountModel::query()->orderBy('id');

        if ($accountIds !== null) {
            $query->whereIn('id', $accountIds);
        }

        $broken = [];

        foreach ($query->cursor() as $account) {
            $rows = DB::table('ledger_entries')
                ->where('account_id', $account->id)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'prev_hash' => $row->prev_hash,
                    'row_hash' => (string) $row->row_hash,
                    'created_at' => (string) $row->created_at,
                    'account_id' => (int) $row->account_id,
                    'amount' => (int) $row->amount,
                    'entry_type' => (string) $row->entry_type,
                    'reference' => $row->reference_type.':'.$row->reference_id,
                    'balance_after' => (int) $row->balance_after,
                ]);

            $bad = $this->hashChain->verify($rows);

            if ($bad !== []) {
                $broken[] = ['account_id' => (int) $account->id, 'entry_ids' => $bad];
            }
        }

        return $broken;
    }

    /** @param  ?array<int, int>  $accountIds */
    private function accountCount(?array $accountIds): int
    {
        return $accountIds === null
            ? LedgerAccountModel::query()->count()
            : count($accountIds);
    }
}
