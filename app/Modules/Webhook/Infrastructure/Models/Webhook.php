<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Infrastructure\Models;

use App\Modules\Webhook\Database\Factories\WebhookFactory;
use App\Modules\Webhook\Domain\WebhookStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member's registered endpoint.
 *
 * `secret_encrypted` is `$hidden` AND has no accessor that returns it: the only
 * way to obtain the plaintext is signingSecret(), which is called from exactly
 * one place (the delivery job). Anything that serialises this model — a
 * Resource, a log line, a queued job payload, `dd()` — gets the row without it.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $url
 * @property list<string> $events
 * @property WebhookStatus $status
 * @property string|null $description
 * @property int $consecutive_failures
 * @property \Illuminate\Support\Carbon|null $failing_since
 * @property \Illuminate\Support\Carbon|null $disabled_at
 */
final class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    protected $table = 'webhooks';

    protected $guarded = [];

    /** Never leaves the process in a serialised form. */
    protected $hidden = ['secret_encrypted', 'secret_hash'];

    protected $casts = [
        'organization_id' => 'integer',
        'events' => 'array',
        'status' => WebhookStatus::class,
        'consecutive_failures' => 'integer',
        'total_failures' => 'integer',
        'total_deliveries' => 'integer',
        // AES-256-GCM under APP_KEY. See the migration for why this is
        // reversible encryption and not a hash.
        'secret_encrypted' => 'encrypted',
        'secret_rotated_at' => 'datetime',
        'failing_since' => 'datetime',
        'last_failure_at' => 'datetime',
        'last_success_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    protected static function newFactory(): WebhookFactory
    {
        return WebhookFactory::new();
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id');
    }

    /**
     * The HMAC key for §3.9. The single legitimate reader of the plaintext.
     *
     * Deliberately a method and not an attribute: a property would be picked up
     * by toArray(), by a Resource that forgets to exclude it, and by the queue
     * serialiser.
     */
    public function signingSecret(): string
    {
        return (string) $this->getAttribute('secret_encrypted');
    }

    /** @return list<string> */
    public function subscribedEvents(): array
    {
        /** @var mixed $events */
        $events = $this->getAttribute('events');

        if (! is_array($events)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $e): string => is_string($e) ? $e : '',
            $events,
        )));
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->subscribedEvents(), true);
    }

    public function isDisabled(): bool
    {
        return $this->status === WebhookStatus::DISABLED;
    }

    /** Constant-time "is this the secret this webhook was issued?" without decrypting. */
    public function secretMatches(string $candidate): bool
    {
        return hash_equals((string) $this->getAttribute('secret_hash'), hash('sha256', $candidate));
    }
}
