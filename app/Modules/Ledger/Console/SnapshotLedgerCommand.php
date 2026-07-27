<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Console;

use App\Modules\Ledger\Application\SnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `php artisan ledger:snapshot` — docs/03-domain/03-ledger.md §3.8.
 *
 * Writes one checkpoint row per account so balance rebuilds stay O(entries
 * since the snapshot) rather than O(all history).
 */
final class SnapshotLedgerCommand extends Command
{
    protected $signature = 'ledger:snapshot {--date= : Snapshot date (Y-m-d), defaults to today}';

    protected $description = 'Write a daily balance snapshot for every ledger account';

    public function handle(SnapshotService $snapshots): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== ''
            ? Carbon::createFromFormat('Y-m-d', $option)
            : Carbon::today();

        if ($date === false) {
            $this->error('Invalid --date; expected Y-m-d.');

            return self::INVALID;
        }

        $written = $snapshots->snapshotAll($date);

        $this->info(sprintf('Wrote %d ledger snapshots for %s.', $written, $date->toDateString()));

        return self::SUCCESS;
    }
}
