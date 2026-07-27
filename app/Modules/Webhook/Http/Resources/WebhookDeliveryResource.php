<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Http\Request;

/**
 * One delivery record — the §3.13 `GET /webhooks/{id}/deliveries` sample, field
 * for field.
 *
 * The payload is included because this endpoint exists for debugging and the
 * first question a member asks is "what did you actually send me?". It is their
 * own organisation's data and the route is tenant-scoped, so there is nothing
 * here they may not see. The SIGNATURE is not included: reproducing it would
 * require the secret, and the header is per-attempt anyway.
 *
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => (int) $delivery->getKey(),
            'event_id' => (string) $delivery->getAttribute('event_id'),
            'event_type' => (string) $delivery->getAttribute('event_type'),
            'status' => $delivery->getAttribute('status')->value,
            'attempts' => (int) $delivery->getAttribute('attempts'),
            'response_code' => $delivery->getAttribute('response_code') === null
                ? null
                : (int) $delivery->getAttribute('response_code'),
            'response_time_ms' => $delivery->getAttribute('response_time_ms') === null
                ? null
                : (int) $delivery->getAttribute('response_time_ms'),
            'last_error' => $delivery->getAttribute('last_error'),
            'next_retry_at' => Display::iso($delivery->getAttribute('next_retry_at')),
            'delivered_at' => Display::iso($delivery->getAttribute('delivered_at')),
            'created_at' => Display::iso($delivery->getAttribute('created_at')),
            'payload' => $delivery->getAttribute('payload'),
        ];
    }
}
