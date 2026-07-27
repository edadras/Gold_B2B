<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Events;

/**
 * Seven consecutive failed attempts — §3.10 «webhook در وضعیت FAILING قرار
 * می‌گیرد ► اعلان به عضو».
 *
 * Scalars only, no models: any module may listen for it by class name, and the
 * Notification module is the intended listener for the «اعلان به عضو» half.
 * Webhook may depend on Shared and Identity only and therefore cannot call the
 * notifier itself; announcing the fact and letting Notification subscribe by
 * string is the same pattern Notification already uses for Trading and
 * Settlement.
 */
final readonly class WebhookFailing
{
    public function __construct(
        public int $webhookId,
        public int $organizationId,
        public string $url,
        public int $consecutiveFailures,
        public string $lastError,
        public string $occurredAt,
    ) {}
}
