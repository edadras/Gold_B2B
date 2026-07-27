<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Application;

use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\Destination;
use App\Modules\Webhook\Domain\Exceptions\UnsafeWebhookUrlException;
use App\Modules\Webhook\Domain\RetrySchedule;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Events\WebhookDisabled;
use App\Modules\Webhook\Events\WebhookFailing;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One attempt at one delivery — §3.9 (signing) and §3.10 (timeout and ladder).
 *
 * ────────────────── WHY THE RETRY LADDER IS NOT THE QUEUE'S ──────────────────
 *
 * `$tries = 1`. Laravel's own retry machinery is deliberately not used, even
 * though it can back off, because §3.10's ladder is a *product* promise and the
 * queue's is an *infrastructure* detail:
 *
 *   · the attempt count and the next attempt time have to be visible to the
 *     member in `GET /webhooks/{id}/deliveries` — they are columns, not a
 *     property of a job payload sitting in Redis;
 *   · the ladder spans twenty-four hours, and a deploy, a worker restart or a
 *     flushed queue in that window must not lose the schedule. It is recorded in
 *     `next_retry_at` and a sweep re-queues anything due, so the ladder survives
 *     the queue being emptied entirely;
 *   · "seven failures then FAILING" is a decision about the ENDPOINT, taken
 *     across deliveries, which a per-job retry counter cannot see.
 *
 * So each attempt is one job, and a failure schedules the next one itself.
 *
 * ─────────────────────── WHAT COUNTS AS SUCCESS ───────────────────────
 *
 * Any 2xx (§3.10 «پاسخ موفق: هر کد ۲xx»). Everything else — 3xx included — is a
 * failure. A redirect is not followed on purpose: following one would let a
 * receiver (or an attacker who has compromised one) bounce the platform's signed
 * POST to `http://169.254.169.254/`, walking straight past every check in
 * OutboundUrlGuard. A member whose endpoint moved must update the registration.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** §3.10 «timeout هر تلاش: ۱۰ ثانیه». */
    public int $timeout = 10;

    /** See the class docblock: the ladder is ours, not the queue's. */
    public int $tries = 1;

    /** Only the id travels: the payload must be read fresh, never carried. */
    public function __construct(public readonly int $deliveryId) {}

    public function handle(OutboundUrlGuard $guard, SignatureGenerator $signer): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        /** @var Webhook|null $webhook */
        $webhook = Webhook::query()->find($delivery->getAttribute('webhook_id'));

        if ($webhook === null) {
            return;
        }

        /** @var DeliveryStatus $status */
        $status = $delivery->getAttribute('status');

        // Already done, or the endpoint has been switched off since this job was
        // queued. Both are ordinary races, not errors.
        if ($status === DeliveryStatus::DELIVERED || $webhook->isDisabled()) {
            return;
        }

        $this->attempt($delivery, $webhook, $guard, $signer);
    }

    private function attempt(
        WebhookDelivery $delivery,
        Webhook $webhook,
        OutboundUrlGuard $guard,
        SignatureGenerator $signer,
    ): void {
        $url = (string) $webhook->getAttribute('url');

        /*
         * THE SSRF CHECK RUNS HERE, NOT ONLY AT REGISTRATION. The host is
         * re-resolved and re-validated immediately before the socket is opened,
         * and the address that passed is pinned into the request below. A zone
         * whose TTL is one second cannot point us at the metadata service
         * between the check and the connect. See OutboundUrlGuard.
         */
        try {
            $destination = $guard->resolveForDelivery($url);
        } catch (UnsafeWebhookUrlException $e) {
            $this->recordFailure($delivery, $webhook, null, 0, 'destination refused: '.$e->reason);

            return;
        }

        $body = $delivery->rawBody();
        $timestamp = time();
        $attempt = (int) $delivery->getAttribute('attempts') + 1;

        $headers = [
            SignatureGenerator::HEADER => $signer->sign($webhook->signingSecret(), $body, $timestamp),
            SignatureGenerator::EVENT_HEADER => (string) $delivery->getAttribute('event_type'),
            SignatureGenerator::EVENT_ID_HEADER => (string) $delivery->getAttribute('event_id'),
            SignatureGenerator::ATTEMPT_HEADER => (string) $attempt,
            'Content-Type' => 'application/json',
            'User-Agent' => 'GoldB2B-Webhook/1.0',
            'Accept' => 'application/json',
        ];

        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('goldb2b.webhook.timeout_seconds', 10))
                ->connectTimeout((int) config('goldb2b.webhook.timeout_seconds', 10))
                ->withOptions($this->transportOptions($destination))
                // The signed bytes and the transmitted bytes are the same string.
                ->withBody($body, 'application/json')
                ->post($destination->url);

            $elapsedMs = $this->elapsedMs($startedAt);

            if ($response->successful()) {
                $this->recordSuccess($delivery, $webhook, $response->status(), $elapsedMs);

                return;
            }

            $this->recordFailure(
                $delivery,
                $webhook,
                $response->status(),
                $elapsedMs,
                sprintf('HTTP %d %s', $response->status(), $this->summarise($response->body())),
            );
        } catch (Throwable $e) {
            // Connection refused, DNS failure, TLS failure, timeout. Never let a
            // member's broken endpoint fail the job itself — that would hand the
            // retry decision back to the queue.
            $this->recordFailure($delivery, $webhook, null, $this->elapsedMs($startedAt), $this->summarise($e->getMessage()));
        }
    }

    /**
     * Transport options that matter for safety, not for style.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(Destination $destination): array
    {
        $options = [
            // See the class docblock: a 3xx is a failure, never a new destination.
            'allow_redirects' => false,
            // A receiver with a broken certificate is a receiver we do not talk
            // to. The payload is member trade data.
            'verify' => true,
            // We do not need, and will not read, an unbounded response body.
            'http_errors' => false,
        ];

        // Pin the connection to the address the guard actually inspected. Without
        // this, cURL performs its own lookup and the check above is advisory.
        if (defined('CURLOPT_RESOLVE') && ! $destination->isIpLiteral()) {
            $options['curl'] = [CURLOPT_RESOLVE => [$destination->curlResolveEntry()]];
        }

        return $options;
    }

    private function recordSuccess(
        WebhookDelivery $delivery,
        Webhook $webhook,
        int $statusCode,
        int $elapsedMs,
    ): void {
        $delivery->forceFill([
            'status' => DeliveryStatus::DELIVERED,
            'attempts' => (int) $delivery->getAttribute('attempts') + 1,
            'response_code' => $statusCode,
            'response_time_ms' => $elapsedMs,
            'last_error' => null,
            'next_retry_at' => null,
            'delivered_at' => now(),
        ])->save();

        // One success clears the streak: §3.10's two thresholds both measure
        // *continuous* failure.
        $webhook->forceFill([
            'consecutive_failures' => 0,
            'failing_since' => null,
            'last_success_at' => now(),
            'last_error' => null,
            'total_deliveries' => (int) $webhook->getAttribute('total_deliveries') + 1,
            'status' => $webhook->getAttribute('status') === WebhookStatus::FAILING
                ? WebhookStatus::ACTIVE
                : $webhook->getAttribute('status'),
        ])->save();
    }

    /**
     * Record one failed attempt, move the delivery along the ladder, and apply
     * §3.10's endpoint-level thresholds.
     */
    private function recordFailure(
        WebhookDelivery $delivery,
        Webhook $webhook,
        ?int $statusCode,
        int $elapsedMs,
        string $error,
    ): void {
        $schedule = RetrySchedule::fromConfig();

        $attempts = (int) $delivery->getAttribute('attempts') + 1;
        $delay = $schedule->delayAfterFailures($attempts);

        $delivery->forceFill([
            'status' => $delay === null ? DeliveryStatus::EXHAUSTED : DeliveryStatus::FAILED,
            'attempts' => $attempts,
            'response_code' => $statusCode,
            'response_time_ms' => $elapsedMs,
            'last_error' => $error,
            'next_retry_at' => $delay === null ? null : now()->addSeconds($delay),
        ])->save();

        $this->applyEndpointThresholds($webhook, $error, $schedule);

        if ($delay !== null && ! $webhook->fresh()?->isDisabled()) {
            self::dispatch($this->deliveryId)->delay($delay);
        }
    }

    /**
     * §3.10's two endpoint-level rules.
     *
     *   · seven consecutive failed attempts  → FAILING + notify the member;
     *   · seventy-two hours of failing       → DISABLED + notify the member.
     *
     * The streak counts ATTEMPTS, not deliveries: a single delivery running the
     * full ladder is exactly seven failures and trips the first threshold, which
     * is the reading the document's own layout implies («پس از ۷ تلاش ناموفق»
     * directly under the seven-row table).
     *
     * Notification is an event, not a call: this module may depend on Shared and
     * Identity only, so it announces and Notification subscribes by class name.
     */
    private function applyEndpointThresholds(Webhook $webhook, string $error, RetrySchedule $schedule): void
    {
        $failures = (int) $webhook->getAttribute('consecutive_failures') + 1;
        $failingSince = $webhook->getAttribute('failing_since') ?? now();

        $webhook->forceFill([
            'consecutive_failures' => $failures,
            'total_failures' => (int) $webhook->getAttribute('total_failures') + 1,
            'last_failure_at' => now(),
            'last_error' => mb_substr($error, 0, (int) config('goldb2b.webhook.max_error_length', 500)),
            'failing_since' => $failingSince,
        ]);

        /** @var WebhookStatus $status */
        $status = $webhook->getAttribute('status');

        $disableAfterHours = (int) config('goldb2b.webhook.disable_after_failing_hours', 72);
        $failingHours = (int) $failingSince->diffInHours(now(), true);

        if ($status !== WebhookStatus::DISABLED && $failingHours >= $disableAfterHours && $failures >= $schedule->maxAttempts()) {
            $webhook->forceFill([
                'status' => WebhookStatus::DISABLED,
                'disabled_at' => now(),
            ])->save();

            event(new WebhookDisabled(
                webhookId: (int) $webhook->getKey(),
                organizationId: (int) $webhook->getAttribute('organization_id'),
                url: (string) $webhook->getAttribute('url'),
                failingHours: $failingHours,
                lastError: $error,
                occurredAt: now()->toIso8601String(),
            ));

            Log::warning('Webhook disabled after prolonged failure', [
                'webhook_id' => $webhook->getKey(),
                'failing_hours' => $failingHours,
            ]);

            return;
        }

        $justStartedFailing = $status === WebhookStatus::ACTIVE && $failures >= $schedule->maxAttempts();

        if ($justStartedFailing) {
            $webhook->setAttribute('status', WebhookStatus::FAILING);
        }

        $webhook->save();

        if ($justStartedFailing) {
            event(new WebhookFailing(
                webhookId: (int) $webhook->getKey(),
                organizationId: (int) $webhook->getAttribute('organization_id'),
                url: (string) $webhook->getAttribute('url'),
                consecutiveFailures: $failures,
                lastError: $error,
                occurredAt: now()->toIso8601String(),
            ));
        }
    }

    /** Nanoseconds → milliseconds, without ever touching a float. */
    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }

    /** A receiver's error page is not a log line; keep the first useful part. */
    private function summarise(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_substr($text, 0, (int) config('goldb2b.webhook.max_error_length', 500));
    }
}
