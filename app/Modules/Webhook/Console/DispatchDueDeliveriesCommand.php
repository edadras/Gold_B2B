<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Console;

use App\Modules\Webhook\Application\DeliverWebhookJob;
use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Events\WebhookDisabled;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * The safety net under the retry ladder, and the seventy-two-hour sweep.
 *
 * Two jobs, both of which exist because time-based state cannot live only in a
 * queue:
 *
 * 1. A delayed job can be lost — a flushed Redis, a worker that died holding it,
 *    a queue migration. `next_retry_at` is the durable record of the ladder, so
 *    anything due and not delivered is re-queued from here. Re-queueing is safe
 *    because the delivery row, not the job, owns the attempt count.
 *
 * 2. §3.10's «پس از ۷۲ ساعت وضعیت DISABLED» is a deadline, and a deadline that
 *    is only evaluated when the next failure happens will never fire for an
 *    endpoint whose ladder has already run out. This command evaluates it on the
 *    clock instead.
 *
 * Intended schedule: every five minutes.
 */
final class DispatchDueDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:dispatch-due {--limit=500 : Maximum deliveries to re-queue in one pass}';

    protected $description = 'Re-queue webhook deliveries whose next attempt is due and disable endpoints failing for too long';

    public function handle(): int
    {
        $this->disableStaleWebhooks();

        $limit = max(1, (int) $this->option('limit'));

        $due = WebhookDelivery::query()
            ->whereIn('status', [DeliveryStatus::QUEUED->value, DeliveryStatus::FAILED->value])
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        foreach ($due as $delivery) {
            DeliverWebhookJob::dispatch((int) $delivery->getKey());
        }

        $this->info(sprintf('Re-queued %d webhook deliveries.', $due->count()));

        return self::SUCCESS;
    }

    private function disableStaleWebhooks(): void
    {
        $hours = (int) config('goldb2b.webhook.disable_after_failing_hours', 72);
        $cutoff = now()->subHours($hours);

        $stale = Webhook::query()
            ->where('status', WebhookStatus::FAILING->value)
            ->whereNotNull('failing_since')
            ->where('failing_since', '<=', $cutoff)
            ->get();

        foreach ($stale as $webhook) {
            $webhook->forceFill([
                'status' => WebhookStatus::DISABLED,
                'disabled_at' => now(),
            ])->save();

            event(new WebhookDisabled(
                webhookId: (int) $webhook->getKey(),
                organizationId: (int) $webhook->getAttribute('organization_id'),
                url: (string) $webhook->getAttribute('url'),
                failingHours: $hours,
                lastError: (string) ($webhook->getAttribute('last_error') ?? ''),
                occurredAt: now()->toIso8601String(),
            ));
        }

        if ($stale->isNotEmpty()) {
            $this->warn(sprintf('Disabled %d webhooks failing for more than %d hours.', $stale->count(), $hours));
        }
    }
}
