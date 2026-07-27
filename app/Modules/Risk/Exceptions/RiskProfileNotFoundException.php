<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** A member without a risk profile cannot trade — fail closed, never open. */
final class RiskProfileNotFoundException extends DomainException
{
    public function __construct(public readonly int $organizationId)
    {
        parent::__construct('RISK_PROFILE_NOT_FOUND');
    }

    public function errorCode(): string
    {
        return 'RISK_PROFILE_NOT_FOUND';
    }

    public function userMessage(): string
    {
        return 'پروفایل ریسک برای این عضو تعریف نشده است.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['organizationId' => $this->organizationId];
    }
}
