<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** Check 2 of §11.4. */
final class OrganizationNotActiveException extends DomainException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct('ORGANIZATION_NOT_ACTIVE');
    }

    public function errorCode(): string
    {
        return 'ORGANIZATION_NOT_ACTIVE';
    }

    public function userMessage(): string
    {
        return 'وضعیت عضویت شما اجازه معامله نمی‌دهد.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['status' => $this->status];
    }
}
