<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * Mints the `evt_…` identifier that §3.8 puts in the payload and §3.9 puts in
 * the `X-GoldB2B-Event-Id` header.
 *
 * §3.11 tells receivers to deduplicate on this id, and tells them the platform
 * «ممکن است یک رویداد را بیش از یک بار ارسال کند». That advice is worthless
 * unless the id is *derived from the event*, not generated per attempt: an id
 * minted with random_bytes() at send time would be different on every redelivery
 * and every receiver's dedup table would grow without ever matching.
 *
 * So the id is a digest of what the event IS — its type, the organisation it is
 * being delivered to, and its data. The same domain event dispatched twice (a
 * replayed queue message, a re-run listener, a manual replay from the console)
 * digests to the same id, and the receiver's `ProcessedWebhook` row from §3.11
 * does its job.
 *
 * The organisation is inside the digest on purpose: a trade has two sides and
 * each side's webhook gets a payload whose `organization_id` differs, so they
 * are two different events and must not share an id — otherwise the buyer's
 * accounting software would discard the seller's copy as a duplicate.
 *
 * Sixteen hex characters (64 bits) matches the width in the document's own
 * example (`evt_a3f9c2b14d5e6f7a`). It is a dedup key, not a secret: it is not
 * required to be unguessable, only to be stable and collision-free in practice.
 */
final class EventIdentity
{
    public const PREFIX = 'evt_';

    private const DIGEST_HEX_LENGTH = 16;

    /**
     * Deterministic id for one (event type, organisation, payload data) triple.
     *
     * Two dispatches of the same business fact with identical data collapse to
     * one id, which is the intended behaviour — the receiver is told to treat a
     * repeated id as already-processed. Every event in the platform carries an
     * id and an `occurredAt`, so distinct facts do not collide.
     *
     * @param  array<string, mixed>  $data  the payload's `data` object
     */
    public static function forEvent(string $eventType, int $organizationId, array $data): string
    {
        $canonical = json_encode(
            [$eventType, $organizationId, self::sortRecursively($data)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return self::PREFIX.substr(hash('sha256', (string) $canonical), 0, self::DIGEST_HEX_LENGTH);
    }

    /**
     * For events that are genuinely one-off and have no business identity to
     * derive from — currently only the §3.13 test ping, where every press of the
     * button is meant to be a new event the receiver has not seen.
     */
    public static function random(): string
    {
        return self::PREFIX.bin2hex(random_bytes(self::DIGEST_HEX_LENGTH / 2));
    }

    /**
     * Key order must not change the digest: the payload builder walks the event
     * object with reflection and a refactor that reorders two constructor
     * promoted properties would otherwise re-issue every id.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursively($value);
            }
        }

        return $data;
    }
}
