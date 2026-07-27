<?php

declare(strict_types=1);

namespace App\Modules\Notification\Console;

use App\Modules\Notification\Infrastructure\Notification;
use App\Modules\Notification\Infrastructure\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan notification:prune` — drops read notifications older than the
 * retention window, and the delivery rows that hang off them.
 *
 * The feed is a convenience, not a record: what actually happened lives in the
 * ledger and the audit log, both of which are append-only and untouched here.
 * Unread notifications are never pruned regardless of age — a member who has
 * not seen a settlement warning still needs to see it.
 */
final class PruneNotificationsCommand extends Command
{
    protected $signature = 'notification:prune
                            {--days=90 : Delete read notifications older than this many days}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Prune read notifications and their delivery rows beyond the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);

        $query = Notification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff->toDateTimeString());

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d read notification(s) older than %d days would be pruned.', $count, $days));

            return self::SUCCESS;
        }

        $deleted = 0;

        (clone $query)->select('id')->chunkById(500, function ($rows) use (&$deleted): void {
            $ids = $rows->pluck('id')->all();

            DB::transaction(function () use ($ids, &$deleted): void {
                NotificationDelivery::query()->whereIn('notification_id', $ids)->delete();
                $deleted += Notification::query()->whereIn('id', $ids)->delete();
            });
        });

        $this->info(sprintf('Pruned %d read notification(s) older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }
}
