<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** The organisation already has as many endpoints as it is allowed. */
final class WebhookLimitReachedException extends DomainException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct('WEBHOOK_LIMIT_REACHED');
    }

    public function errorCode(): string
    {
        return 'WEBHOOK_LIMIT_REACHED';
    }

    public function userMessage(): string
    {
        return 'حداکثر تعداد webhook مجاز ثبت شده است.';
    }

    public function details(): array
    {
        return ['limit' => $this->limit];
    }
}
