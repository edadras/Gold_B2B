<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Console;

use App\Modules\Ledger\Application\ReconciliationService;
use Illuminate\Console\Command;

/**
 * `php artisan ledger:reconcile` — docs/03-domain/03-ledger.md §3.7.
 *
 * Scheduled daily at 02:00 for the full sweep, hourly for accounts touched
 * today. Exits non-zero when anything is wrong so the scheduler alerts.
 */
final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile
        {--account=* : Restrict to these account ids}
        {--hashes : Also recompute every hash chain (slow; nightly at 03:00)}';

    protected $description = 'Verify ledger invariants: balances, group balancing, conservation and hash chains';

    public function handle(ReconciliationService $reconciliation): int
    {
        $accountIds = array_map('intval', (array) $this->option('account'));
        $report = $reconciliation->reconcile(
            $accountIds === [] ? null : $accountIds,
            (bool) $this->option('hashes'),
        );

        $this->line(sprintf('Accounts checked: %d', $report['accounts_checked']));

        foreach ($report['conservation'] as $asset => $sum) {
            $sum === 0
                ? $this->line(sprintf('  %s conservation: 0 OK', $asset))
                : $this->error(sprintf('  ⛔ %s ledger does not balance: %d (expected 0)', $asset, $sum));
        }

        foreach ($report['discrepancies'] as $row) {
            $this->error(sprintf(
                '  ⛔ Account %d (%s/%s org %d): stored %d, computed %d, difference %d',
                $row['account_id'],
                $row['asset_type'],
                $row['bucket'],
                $row['organization_id'],
                $row['stored'],
                $row['computed'],
                $row['difference'],
            ));
        }

        foreach ($report['negative_balances'] as $row) {
            $this->error(sprintf(
                '  ⛔ Account %d (%s) holds a negative balance of %d',
                $row['account_id'],
                $row['bucket'],
                $row['balance'],
            ));
        }

        foreach ($report['unbalanced_groups'] as $row) {
            $this->error(sprintf(
                '  ⛔ Unbalanced transaction group %s (%s: %d)',
                $row['transaction_group'],
                $row['asset_type'],
                $row['sum'],
            ));
        }

        foreach ($report['broken_hash_chains'] as $row) {
            $this->error(sprintf(
                '  ⛔ Hash chain broken on account %d at entries: %s',
                $row['account_id'],
                implode(', ', $row['entry_ids']),
            ));
        }

        if ($report['healthy'] === true) {
            $this->info('Ledger is consistent.');

            return self::SUCCESS;
        }

        $this->error('Ledger reconciliation FAILED — affected organisations should be frozen.');

        return self::FAILURE;
    }
}
