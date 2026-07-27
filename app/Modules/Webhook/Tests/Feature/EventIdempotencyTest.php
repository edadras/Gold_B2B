<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Feature;

use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhook\Listeners\DispatchWebhooksForDomainEvent;
use App\Modules\Webhook\Tests\Support\FakeTradeExecuted;
use App\Modules\Webhook\Tests\WebhookTestCase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * §3.8 (payload shape) and §3.11 (idempotency on the receiver's side).
 *
 * The platform's half of the §3.11 contract is that a redelivered event carries
 * the SAME `event_id`. If it did not, the receiver's `ProcessedWebhook` table
 * would never match and the deduplication the document asks for would be
 * impossible to implement.
 */
final class EventIdempotencyTest extends WebhookTestCase
{
    private const BUYER_ORG = 184;

    private const SELLER_ORG = 291;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // The double stands in for the real producing event; see FakeTradeExecuted.
        config()->set('goldb2b.webhook.event_map', [
            FakeTradeExecuted::class => WebhookEventType::TRADE_EXECUTED->value,
        ]);
    }

    #[Test]
    public function the_same_domain_event_redelivered_reuses_the_same_event_id(): void
    {
        $webhook = $this->subscribedWebhook(self::BUYER_ORG);

        $listener = $this->app->make(DispatchWebhooksForDomainEvent::class);
        $event = $this->tradeEvent();

        $listener->handle($event);

        $first = WebhookDelivery::query()->where('webhook_id', $webhook->getKey())->sole();
        $firstEventId = (string) $first->getAttribute('event_id');

        // The listener fires again: a replayed queue message, a retried
        // transaction, an operator re-running a backfill.
        $listener->handle($event);

        $rows = WebhookDelivery::query()->where('webhook_id', $webhook->getKey())->get();

        self::assertCount(1, $rows, 'a redelivery must not create a second delivery row');
        self::assertSame($firstEventId, (string) $rows->first()?->getAttribute('event_id'));

        // A structurally identical event constructed from scratch digests the
        // same way — the id is derived from the event, not from the object.
        $listener->handle($this->tradeEvent());

        self::assertSame(
            1,
            WebhookDelivery::query()->where('webhook_id', $webhook->getKey())->count(),
        );
    }

    #[Test]
    public function the_event_id_has_the_documented_shape_and_appears_in_the_payload(): void
    {
        $webhook = $this->subscribedWebhook(self::BUYER_ORG);

        $this->app->make(DispatchWebhooksForDomainEvent::class)->handle($this->tradeEvent());

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::query()->where('webhook_id', $webhook->getKey())->sole();

        $eventId = (string) $delivery->getAttribute('event_id');

        // `evt_a3f9c2b14d5e6f7a` — prefix plus sixteen hex characters.
        self::assertMatchesRegularExpression('/^evt_[0-9a-f]{16}$/', $eventId);

        /** @var array<string, mixed> $payload */
        $payload = $delivery->getAttribute('payload');

        // §3.8, field for field.
        self::assertSame($eventId, $payload['id']);
        self::assertSame('trade.executed', $payload['type']);
        self::assertSame('v1', $payload['api_version']);
        self::assertSame(self::BUYER_ORG, $payload['organization_id']);
        self::assertIsString($payload['created_at']);
        self::assertIsArray($payload['data']);

        // Scalars are copied and snake_cased; the object property is not.
        self::assertSame('TRD-00088231', $payload['data']['trade_code']);
        self::assertSame(250_000, $payload['data']['fine_weight_mg']);
        self::assertArrayNotHasKey('internal_context', $payload['data']);
    }

    /**
     * A trade has two sides. Each side's envelope names a different
     * organisation, so they are different events and must not collide — sharing
     * an id would make the buyer's software discard the seller's copy.
     */
    #[Test]
    public function each_party_receives_its_own_event_with_its_own_id(): void
    {
        $buyerHook = $this->subscribedWebhook(self::BUYER_ORG);
        $sellerHook = $this->subscribedWebhook(self::SELLER_ORG);

        $this->app->make(DispatchWebhooksForDomainEvent::class)->handle($this->tradeEvent());

        $buyerDelivery = WebhookDelivery::query()->where('webhook_id', $buyerHook->getKey())->sole();
        $sellerDelivery = WebhookDelivery::query()->where('webhook_id', $sellerHook->getKey())->sole();

        self::assertNotSame(
            (string) $buyerDelivery->getAttribute('event_id'),
            (string) $sellerDelivery->getAttribute('event_id'),
        );

        self::assertSame(self::BUYER_ORG, $buyerDelivery->getAttribute('payload')['organization_id']);
        self::assertSame(self::SELLER_ORG, $sellerDelivery->getAttribute('payload')['organization_id']);
    }

    #[Test]
    public function an_endpoint_that_did_not_subscribe_to_the_type_receives_nothing(): void
    {
        Webhook::factory()
            ->forOrganization(self::BUYER_ORG)
            ->subscribedTo([WebhookEventType::SETTLEMENT_COMPLETED])
            ->create();

        $this->app->make(DispatchWebhooksForDomainEvent::class)->handle($this->tradeEvent());

        self::assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function another_organisations_endpoint_receives_nothing(): void
    {
        $this->subscribedWebhook(999);

        $this->app->make(DispatchWebhooksForDomainEvent::class)->handle($this->tradeEvent());

        self::assertSame(0, WebhookDelivery::query()->count());
    }

    /** An unmapped event class is silently ignored, not an error. */
    #[Test]
    public function an_unmapped_event_is_ignored(): void
    {
        config()->set('goldb2b.webhook.event_map', []);

        $this->subscribedWebhook(self::BUYER_ORG);

        $this->app->make(DispatchWebhooksForDomainEvent::class)->handle($this->tradeEvent());

        self::assertSame(0, WebhookDelivery::query()->count());
    }

    private function subscribedWebhook(int $organizationId): Webhook
    {
        /** @var Webhook $webhook */
        $webhook = Webhook::factory()
            ->forOrganization($organizationId)
            ->subscribedTo([WebhookEventType::TRADE_EXECUTED])
            ->withUrl('https://hook.member-example.com/goldb2b')
            ->create();

        return $webhook;
    }

    private function tradeEvent(): FakeTradeExecuted
    {
        return new FakeTradeExecuted(
            tradeId: 88231,
            tradeCode: 'TRD-00088231',
            buyerOrganizationId: self::BUYER_ORG,
            sellerOrganizationId: self::SELLER_ORG,
            fineWeightMg: 250_000,
            pricePerGramRial: 78_480_000,
            makerSide: null,
            executedAt: '2026-07-27T09:15:33.480Z',
        );
    }
}
