<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The destination URL is not somewhere this platform will send a signed,
 * authenticated POST. See Application\OutboundUrlGuard for why.
 *
 * 422 rather than 403: the member is allowed to register webhooks, this
 * particular URL is unacceptable — it is a validation failure on the input.
 */
final class UnsafeWebhookUrlException extends DomainException
{
    public function __construct(
        public readonly string $url,
        public readonly string $reason,
    ) {
        parent::__construct('WEBHOOK_URL_NOT_ALLOWED: '.$reason);
    }

    public function errorCode(): string
    {
        return 'WEBHOOK_URL_NOT_ALLOWED';
    }

    public function userMessage(): string
    {
        return 'آدرس webhook پذیرفته نشد. آدرس باید HTTPS و روی یک میزبان عمومی باشد.';
    }

    public function details(): array
    {
        return ['reason' => $this->reason];
    }
}
