<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Application;

/**
 * The `X-GoldB2B-Signature` header of §3.9 and docs/02-architecture/04-security.md §4.8.
 *
 *     signed_payload = "{timestamp}.{raw_request_body}"
 *     signature      = HMAC_SHA256(webhook_secret, signed_payload)
 *     header         = "t={timestamp},v1={signature}"
 *
 * Three details are load-bearing and easy to get subtly wrong:
 *
 * 1. THE BODY IS SIGNED RAW. The receiver verifies against the exact bytes it
 *    read off the socket. If the signer serialises the payload once for hashing
 *    and again for sending, any difference — key order, unicode escaping, a
 *    trailing newline — makes every signature invalid. So the caller hands this
 *    class the string it is going to transmit, and transmits that same string.
 *
 * 2. THE TIMESTAMP IS INSIDE THE HASH. Without it an attacker who captured one
 *    valid (body, signature) pair could replay it forever. With it, the pair is
 *    only valid inside the five-minute window of §4.8, because changing the
 *    timestamp invalidates the signature and keeping it makes the message stale.
 *
 * 3. COMPARISON IS CONSTANT-TIME. verify() uses hash_equals, never `===`. A
 *    byte-by-byte comparison that short-circuits leaks, over enough requests,
 *    how much of a guessed signature was correct.
 *
 * verify() is the mirror of sign() and is what the module's tests assert
 * against; it is also exactly the routine §3.9 tells members to implement, kept
 * here so the platform's own tests prove the published sample works.
 */
final class SignatureGenerator
{
    public const HEADER = 'X-GoldB2B-Signature';

    public const EVENT_HEADER = 'X-GoldB2B-Event';

    public const EVENT_ID_HEADER = 'X-GoldB2B-Event-Id';

    public const ATTEMPT_HEADER = 'X-GoldB2B-Delivery-Attempt';

    private const ALGORITHM = 'sha256';

    /** §4.8 «درخواست با انحراف بیش از ۵ دقیقه رد می‌شود». */
    private const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * The complete header value for one request.
     *
     * @param  string  $rawBody  the exact bytes that will be sent
     */
    public function sign(string $secret, string $rawBody, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, $this->signature($secret, $rawBody, $timestamp));
    }

    /** The bare hex digest, without the `t=`/`v1=` wrapper. */
    public function signature(string $secret, string $rawBody, int $timestamp): string
    {
        return hash_hmac(self::ALGORITHM, $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Verify a header exactly as a receiver would — the platform's own copy of
     * the §3.9 sample.
     *
     * Returns false, never throws: a bad signature is an ordinary outcome, and
     * an exception whose message distinguishes "malformed header" from "wrong
     * digest" is an oracle.
     */
    public function verify(
        string $secret,
        string $rawBody,
        string $header,
        ?int $toleranceSeconds = null,
        ?int $now = null,
    ): bool {
        $parts = self::parseHeader($header);

        $timestamp = isset($parts['t']) && ctype_digit($parts['t']) ? (int) $parts['t'] : 0;
        $provided = $parts['v1'] ?? '';

        if ($timestamp <= 0 || $provided === '') {
            return false;
        }

        // 1) replay window
        $tolerance = $toleranceSeconds ?? $this->toleranceSeconds();

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        // 2) constant-time comparison
        return hash_equals($this->signature($secret, $rawBody, $timestamp), $provided);
    }

    public function toleranceSeconds(): int
    {
        return (int) config('goldb2b.webhook.signature_tolerance_seconds', self::DEFAULT_TOLERANCE_SECONDS);
    }

    /**
     * `t=1735689600,v1=5257a8…` → ['t' => '1735689600', 'v1' => '5257a8…'].
     *
     * Unknown keys are kept rather than rejected: the scheme is versioned by the
     * `v1` key so a future `v2` can be added alongside without breaking a
     * receiver that only knows about v1, and this parser must behave the same
     * way as the ones members write from the doc.
     *
     * @return array<string, string>
     */
    public static function parseHeader(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || ! str_contains($segment, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $segment, 2);
            $parts[trim($key)] = trim($value);
        }

        return $parts;
    }
}
