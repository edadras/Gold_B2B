<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * An operation was attempted on an endpoint the platform has switched off after
 * seventy-two hours of failure (§3.10). Sending a test ping or queueing a new
 * event is refused until the member updates the webhook.
 */
final class WebhookDisabledException extends DomainException
{
    public function __construct(public readonly int $webhookId)
    {
        parent::__construct('WEBHOOK_DISABLED');
    }

    public function errorCode(): string
    {
        return 'WEBHOOK_DISABLED';
    }

    public function userMessage(): string
    {
        return 'این webhook به دلیل خطاهای پیاپی غیرفعال شده است. پس از اصلاح آدرس، آن را به‌روزرسانی کنید.';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
