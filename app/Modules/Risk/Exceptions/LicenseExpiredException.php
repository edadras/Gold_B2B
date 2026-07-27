<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** Check 3 of §11.4. */
final class LicenseExpiredException extends DomainException
{
    public function __construct(public readonly ?string $expiredAt = null)
    {
        parent::__construct('LICENSE_EXPIRED');
    }

    public function errorCode(): string
    {
        return 'LICENSE_EXPIRED';
    }

    public function userMessage(): string
    {
        return 'مجوز کسب‌وکار شما منقضی شده است.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['expiredAt' => $this->expiredAt];
    }
}
