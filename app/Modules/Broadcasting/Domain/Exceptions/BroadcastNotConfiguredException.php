<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * No app key/secret is configured, so no channel authorisation can be issued.
 *
 * 503, not 403. The caller is not forbidden — the realtime layer is not
 * running. A 403 would send a correctly-authorised client into a permission
 * dialogue instead of into the exponential-backoff reconnect loop of §3.4,
 * which is exactly the behaviour that fits a server-side outage.
 */
final class BroadcastNotConfiguredException extends DomainException
{
    public function errorCode(): string
    {
        return 'BROADCAST_UNAVAILABLE';
    }

    public function userMessage(): string
    {
        return 'سرویس بی‌درنگ در دسترس نیست.';
    }

    public function httpStatus(): int
    {
        return 503;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return ['reason' => 'broadcast_app_credentials_missing'];
    }
}
