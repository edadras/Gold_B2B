<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * Lifecycle of a registered endpoint — docs/05-api/03-realtime-webhooks.md §3.10.
 *
 *   ACTIVE   → deliveries are queued and attempted.
 *   FAILING  → seven consecutive failed attempts. Still attempted: the member's
 *              server may simply be restarting, and silently stopping would lose
 *              events. The member is notified so they can fix it.
 *   DISABLED → seventy-two hours of continuous failure. Nothing is queued any
 *              more; the member must fix the endpoint and re-enable it, which
 *              here means updating the webhook (PUT) once the URL works.
 *
 * The transition back to ACTIVE is a single successful delivery — from FAILING
 * automatically, from DISABLED only after the member touches the row, because a
 * disabled endpoint has nothing queued that could succeed on its own.
 */
enum WebhookStatus: string
{
    case ACTIVE = 'ACTIVE';
    case FAILING = 'FAILING';
    case DISABLED = 'DISABLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** A DISABLED endpoint receives nothing; a FAILING one still gets tried. */
    public function acceptsDeliveries(): bool
    {
        return $this !== self::DISABLED;
    }
}
