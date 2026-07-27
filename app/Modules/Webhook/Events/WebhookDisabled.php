<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Events;

/**
 * Seventy-two hours of continuous failure — §3.10 «پس از ۷۲ ساعت وضعیت
 * DISABLED». Nothing is queued for this endpoint any more, which is a fact the
 * member has to be told about, so this event is the notification trigger.
 *
 * Scalars only; see WebhookFailing for why the module announces rather than
 * notifies.
 */
final readonly class WebhookDisabled
{
    public function __construct(
        public int $webhookId,
        public int $organizationId,
        public string $url,
        public int $failingHours,
        public string $lastError,
        public string $occurredAt,
    ) {}
}
