<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Database\Factories;

use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\EventIdentity;
use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        $eventId = EventIdentity::random();

        return [
            'webhook_id' => 1,
            'event_id' => $eventId,
            'event_type' => WebhookEventType::TRADE_EXECUTED->value,
            'payload' => [
                'id' => $eventId,
                'type' => WebhookEventType::TRADE_EXECUTED->value,
                'api_version' => 'v1',
                'created_at' => now()->toIso8601String(),
                'organization_id' => 1,
                'data' => ['trade_code' => 'TRD-00088231'],
            ],
            'status' => DeliveryStatus::QUEUED,
            'attempts' => 0,
        ];
    }

    public function forWebhook(int $webhookId): self
    {
        return $this->state(fn (): array => ['webhook_id' => $webhookId]);
    }

    public function status(DeliveryStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
