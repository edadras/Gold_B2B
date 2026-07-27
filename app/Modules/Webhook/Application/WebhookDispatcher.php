<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Application;

use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\EventIdentity;
use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Turns "this happened to organisation N" into queued deliveries.
 *
 * The §3.8 envelope is built here and frozen into the delivery row:
 *
 *     { id, type, api_version, created_at, organization_id, data }
 *
 * ONE DELIVERY PER (EVENT, ENDPOINT), CREATED WITH firstOrCreate. Everything
 * about §3.11's idempotency contract rests on that: the event id is derived from
 * the event's content (Domain\EventIdentity), so a listener that fires twice, a
 * queue message replayed after a worker crash, or an operator re-running a
 * backfill all resolve to the same row and re-send the same id. The receiver's
 * `ProcessedWebhook` check then does exactly what the document promises. If the
 * id were minted per attempt instead, "we may send an event more than once"
 * would be true and the advice for coping with it would be useless.
 *
 * The job is dispatched afterCommit(): AGENT_BRIEF rule 3, and more concretely,
 * a worker is fast enough to pick the job up before an uncommitted delivery row
 * is visible to it.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * Fan one domain event out to every subscribed endpoint of one organisation.
     *
     * @param  array<string, mixed>  $data  the payload's `data` object
     * @return list<WebhookDelivery>
     */
    public function dispatch(WebhookEventType $type, int $organizationId, array $data): array
    {
        $webhooks = $this->subscribedWebhooks($type, $organizationId);

        if ($webhooks === []) {
            return [];
        }

        // Derived once, outside the loop: every endpoint of this organisation
        // receives the same event and therefore the same id.
        $eventId = EventIdentity::forEvent($type->value, $organizationId, $data);
        $payload = $this->envelope($eventId, $type->value, $organizationId, $data);

        $deliveries = [];

        foreach ($webhooks as $webhook) {
            $delivery = $this->queue($webhook, $eventId, $type->value, $payload);

            if ($delivery !== null) {
                $deliveries[] = $delivery;
            }
        }

        return $deliveries;
    }

    /**
     * Same, for several organisations at once — a trade has two sides and both
     * are entitled to their own copy.
     *
     * Each organisation gets its own event id because each gets its own envelope
     * (`organization_id` differs). Sharing one id across the two sides would make
     * the buyer's accounting software discard the seller's event as a duplicate.
     *
     * @param  list<int>  $organizationIds
     * @param  array<string, mixed>  $data
     * @return list<WebhookDelivery>
     */
    public function dispatchToAll(WebhookEventType $type, array $organizationIds, array $data): array
    {
        $deliveries = [];

        foreach (array_unique(array_filter($organizationIds, static fn (int $id): bool => $id > 0)) as $organizationId) {
            foreach ($this->dispatch($type, $organizationId, $data) as $delivery) {
                $deliveries[] = $delivery;
            }
        }

        return $deliveries;
    }

    /**
     * §3.13 `POST /webhooks/{id}/test` — «ارسال رویداد آزمایشی».
     *
     * A fresh random id every time, not a derived one: pressing the button twice
     * is meant to produce two events the receiver actually processes, so that a
     * member debugging their integration sees a second request arrive rather
     * than silently hitting our own deduplication.
     */
    public function sendTest(Webhook $webhook): WebhookDelivery
    {
        $eventId = EventIdentity::random();

        $payload = $this->envelope(
            $eventId,
            WebhookEventType::TEST_EVENT_TYPE,
            (int) $webhook->getAttribute('organization_id'),
            [
                'message' => 'این یک رویداد آزمایشی است.',
                'webhook_id' => (int) $webhook->getKey(),
                'triggered_at' => now()->toIso8601String(),
            ],
        );

        $delivery = $this->queue($webhook, $eventId, WebhookEventType::TEST_EVENT_TYPE, $payload, force: true);

        // force: true means the row is always created, so this cannot be null.
        assert($delivery instanceof WebhookDelivery);

        return $delivery;
    }

    /** Re-queue an existing row — §3.13 `POST /webhook-deliveries/{id}/retry`. */
    public function retry(WebhookDelivery $delivery): WebhookDelivery
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::QUEUED,
            'next_retry_at' => null,
        ])->save();

        DeliverWebhookJob::dispatch((int) $delivery->getKey())->afterCommit();

        return $delivery;
    }

    /**
     * The endpoints of one organisation that asked for this event type.
     *
     * DISABLED endpoints are excluded at the query, not later: §3.10 says a
     * disabled webhook stops receiving, and creating rows that will never be
     * attempted would fill the history with noise. FAILING ones are included —
     * the member's server may be back.
     *
     * @return list<Webhook>
     */
    private function subscribedWebhooks(WebhookEventType $type, int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        /** @var list<Webhook> $candidates */
        $candidates = Webhook::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [WebhookStatus::ACTIVE->value, WebhookStatus::FAILING->value])
            // JSON containment would push this into the database, but the
            // subscription list is a handful of strings on a handful of rows per
            // organisation and MariaDB's JSON support differs from MySQL's here.
            // Filtering in PHP keeps the query portable and the semantics exact.
            ->get()
            ->all();

        return array_values(array_filter(
            $candidates,
            static fn (Webhook $webhook): bool => $webhook->subscribesTo($type->value),
        ));
    }

    /**
     * The §3.8 envelope, in the document's field order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $eventId, string $type, int $organizationId, array $data): array
    {
        return [
            'id' => $eventId,
            'type' => $type,
            'api_version' => (string) config('goldb2b.webhook.api_version', 'v1'),
            // Millisecond precision, as in the document's own sample.
            'created_at' => now()->toIso8601ZuluString('millisecond'),
            'organization_id' => $organizationId,
            'data' => $data,
        ];
    }

    /**
     * Create-or-find the delivery row and put it on the queue.
     *
     * Returns null when the event was already delivered to this endpoint: there
     * is nothing to do and re-sending would be a duplicate the receiver has
     * already seen.
     *
     * @param  array<string, mixed>  $payload
     */
    private function queue(
        Webhook $webhook,
        string $eventId,
        string $eventType,
        array $payload,
        bool $force = false,
    ): ?WebhookDelivery {
        // Refuse up front rather than at attempt time: if the stored URL has
        // become unsafe (a rebinding zone, a host that now points at RFC1918)
        // there is no point recording a delivery we will never make.
        if (! $this->guard->isRegistrable((string) $webhook->getAttribute('url'))) {
            Log::warning('Webhook destination is no longer safe; delivery not queued', [
                'webhook_id' => $webhook->getKey(),
                'event_type' => $eventType,
            ]);

            return null;
        }

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::query()->firstOrCreate(
            [
                'webhook_id' => (int) $webhook->getKey(),
                'event_id' => $eventId,
            ],
            [
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => DeliveryStatus::QUEUED,
                'attempts' => 0,
            ],
        );

        if (! $force && ! $delivery->wasRecentlyCreated) {
            /** @var DeliveryStatus $status */
            $status = $delivery->getAttribute('status');

            if ($status === DeliveryStatus::DELIVERED) {
                return null;
            }
        }

        DeliverWebhookJob::dispatch((int) $delivery->getKey())->afterCommit();

        return $delivery;
    }
}
