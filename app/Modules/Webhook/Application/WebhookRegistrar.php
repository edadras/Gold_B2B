<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Application;

use App\Modules\Webhook\Contracts\IssuedSecret;
use App\Modules\Webhook\Domain\Exceptions\WebhookLimitReachedException;
use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registration, subscription changes, secret rotation and removal — §3.7 and
 * the first five rows of the §3.13 table.
 *
 * Every entry point takes an explicit organisation id and every query filters on
 * it. Tenancy is asserted here, in the service, as well as in the controller:
 * the controller's check protects the HTTP surface, this one protects any future
 * caller (a console command, a listener, an admin tool) that does not go through
 * a controller at all.
 */
final class WebhookRegistrar
{
    /** §3.7 shows `whsec_a3f9c2b1…`; 32 random bytes is 256 bits of key. */
    private const SECRET_PREFIX = 'whsec_';

    private const SECRET_BYTES = 32;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /** @return Collection<int, Webhook> */
    public function listForOrganization(int $organizationId): Collection
    {
        return Webhook::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A webhook belonging to somebody else is indistinguishable from one that
     * does not exist — the caller turns null into 404, never 403, so the id
     * space cannot be probed for other members' registrations.
     */
    public function findForOrganization(int $webhookId, int $organizationId): ?Webhook
    {
        return Webhook::query()
            ->where('id', $webhookId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * §3.7. The returned IssuedSecret carries the plaintext; it is the only time
     * it exists outside the encrypted column.
     *
     * @param  list<string>  $events  catalogue values from §3.12
     */
    public function register(
        int $organizationId,
        string $url,
        array $events,
        ?string $description = null,
    ): IssuedSecret {
        // Outside the transaction: it performs a DNS lookup, and AGENT_BRIEF
        // rule 3 keeps network calls out of transactions.
        $url = $this->guard->assertRegistrable($url);
        $events = $this->normaliseEvents($events);

        $limit = (int) config('goldb2b.webhook.max_per_organization', 10);

        if (Webhook::query()->where('organization_id', $organizationId)->count() >= $limit) {
            throw new WebhookLimitReachedException($limit);
        }

        $secret = $this->mintSecret();

        $webhook = DB::transaction(static function () use ($organizationId, $url, $events, $description, $secret): Webhook {
            return Webhook::query()->create([
                'organization_id' => $organizationId,
                'url' => $url,
                'events' => $events,
                'secret_encrypted' => $secret,
                'secret_hash' => hash('sha256', $secret),
                'secret_last_four' => substr($secret, -4),
                'status' => WebhookStatus::ACTIVE,
                'description' => $description,
            ]);
        });

        return new IssuedSecret($webhook, $secret);
    }

    /**
     * §3.13 `PUT /webhooks/{id}` — «ویرایش رویدادها». The URL and description may
     * change too; the secret may not (that is rotate-secret's job).
     *
     * A successful update RESETS THE FAILURE STATE. That is the only way back
     * from DISABLED, and it is the right one: the member has just told us the
     * endpoint is different or fixed, so the seventy-two-hour clock of §3.10 has
     * no meaning any more. Leaving it disabled would require a second, undocumented
     * "re-enable" endpoint.
     *
     * @param  list<string>|null  $events
     */
    public function update(
        Webhook $webhook,
        ?string $url = null,
        ?array $events = null,
        ?string $description = null,
    ): Webhook {
        $normalisedUrl = $url === null ? null : $this->guard->assertRegistrable($url);
        $normalisedEvents = $events === null ? null : $this->normaliseEvents($events);

        return DB::transaction(static function () use ($webhook, $normalisedUrl, $normalisedEvents, $description): Webhook {
            /** @var Webhook $locked */
            $locked = Webhook::query()->lockForUpdate()->findOrFail($webhook->getKey());

            if ($normalisedUrl !== null) {
                $locked->setAttribute('url', $normalisedUrl);
            }

            if ($normalisedEvents !== null) {
                $locked->setAttribute('events', $normalisedEvents);
            }

            if ($description !== null) {
                $locked->setAttribute('description', $description);
            }

            $locked->setAttribute('status', WebhookStatus::ACTIVE);
            $locked->setAttribute('consecutive_failures', 0);
            $locked->setAttribute('failing_since', null);
            $locked->setAttribute('disabled_at', null);
            $locked->save();

            return $locked;
        });
    }

    /**
     * §3.13 `POST /webhooks/{id}/rotate-secret` — «چرخش کلید».
     *
     * The old secret stops working the instant this commits. There is no overlap
     * window: a webhook has exactly one valid signing key, and a member who
     * rotates is telling us the old one is compromised. If a graceful rollover
     * is ever wanted it needs a second column and a documented grace period, not
     * a silently-accepted stale key.
     *
     * Deliveries already queued are signed at SEND time with whatever key is
     * current, so an in-flight retry after a rotation is signed with the new key
     * — which is what a member who has just updated their configuration expects.
     */
    public function rotateSecret(Webhook $webhook): IssuedSecret
    {
        $secret = $this->mintSecret();

        $updated = DB::transaction(static function () use ($webhook, $secret): Webhook {
            /** @var Webhook $locked */
            $locked = Webhook::query()->lockForUpdate()->findOrFail($webhook->getKey());

            $locked->forceFill([
                'secret_encrypted' => $secret,
                'secret_hash' => hash('sha256', $secret),
                'secret_last_four' => substr($secret, -4),
                'secret_rotated_at' => now(),
            ])->save();

            return $locked;
        });

        return new IssuedSecret($updated, $secret);
    }

    /**
     * §3.13 `DELETE /webhooks/{id}`.
     *
     * The delivery history goes with it. It is the member's own data about their
     * own endpoint, it contains their payloads, and keeping it after they asked
     * for the endpoint to be removed would be retention without a purpose. The
     * ledger's append-only rule does not apply here — this is an integration
     * log, not a financial record.
     */
    public function delete(Webhook $webhook): void
    {
        DB::transaction(static function () use ($webhook): void {
            $webhook->deliveries()->delete();
            $webhook->delete();
        });
    }

    /**
     * Accepts only catalogue values, de-duplicated, order-stable.
     *
     * Unknown strings are DROPPED rather than stored: the FormRequest rejects
     * them with a field error, so anything reaching here is a programmatic
     * caller, and silently persisting an event name nothing will ever emit would
     * leave a member believing they are subscribed to something.
     *
     * @param  list<string>  $events
     * @return list<string>
     */
    private function normaliseEvents(array $events): array
    {
        $valid = [];

        foreach ($events as $event) {
            if (! is_string($event)) {
                continue;
            }

            $type = WebhookEventType::tryFrom($event);

            if ($type !== null && ! in_array($type->value, $valid, true)) {
                $valid[] = $type->value;
            }
        }

        if ($valid === []) {
            throw new InvalidArgumentException('A webhook must subscribe to at least one known event type.');
        }

        return $valid;
    }

    private function mintSecret(): string
    {
        return self::SECRET_PREFIX.bin2hex(random_bytes(self::SECRET_BYTES));
    }
}
