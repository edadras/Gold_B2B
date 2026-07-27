<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Infrastructure\Models;

use App\Modules\Webhook\Database\Factories\WebhookDeliveryFactory;
use App\Modules\Webhook\Domain\DeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event, one endpoint, up to seven attempts.
 *
 * @property int $id
 * @property int $webhook_id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_code
 * @property int|null $response_time_ms
 * @property string|null $last_error
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $delivered_at
 */
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $table = 'webhook_deliveries';

    protected $guarded = [];

    protected $casts = [
        'webhook_id' => 'integer',
        'payload' => 'array',
        'status' => DeliveryStatus::class,
        'attempts' => 'integer',
        'response_code' => 'integer',
        'response_time_ms' => 'integer',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }

    /** @return BelongsTo<Webhook, $this> */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }

    /**
     * The exact bytes that are signed and sent.
     *
     * Serialisation happens HERE and nowhere else, with fixed flags, so the body
     * used to compute the HMAC and the body written to the socket cannot
     * possibly differ — see SignatureGenerator, point 1. JSON_UNESCAPED_UNICODE
     * keeps Persian counterparty names readable instead of turning them into
     * \uXXXX escapes that triple the payload size.
     */
    public function rawBody(): string
    {
        return (string) json_encode(
            $this->getAttribute('payload'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }
}
