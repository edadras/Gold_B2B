<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Console;

use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * Retention for the delivery log.
 *
 * `payload` holds a member's trade prices, counterparties and settlement
 * amounts. It is stored so that the member can debug their integration, which is
 * a need measured in days, not years, so it is deleted on a schedule rather than
 * accumulating because nobody wrote the sweeper.
 *
 * Deliveries still in flight are never pruned regardless of age — a row with a
 * future `next_retry_at` is on the twenty-four-hour rung of the ladder, not
 * history.
 *
 * Intended schedule: nightly.
 */
final class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:prune {--days= : Override the configured retention window}';

    protected $description = 'Delete webhook delivery records older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('goldb2b.webhook.delivery_retention_days', 30));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $deleted = WebhookDelivery::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNull('next_retry_at')
            ->delete();

        $this->info(sprintf('Pruned %d webhook deliveries older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }
}
