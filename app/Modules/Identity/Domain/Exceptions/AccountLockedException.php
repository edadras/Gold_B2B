<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Too many failed logins (docs/02-architecture/04-security.md §4.2: 10 attempts
 * then a 30-minute lock).
 */
final class AccountLockedException extends DomainException
{
    public function __construct(
        public readonly string $lockedUntil,
        public readonly int $secondsRemaining,
    ) {
        parent::__construct('ACCOUNT_LOCKED');
    }

    public function errorCode(): string
    {
        return 'ACCOUNT_LOCKED';
    }

    public function userMessage(): string
    {
        return 'به دلیل تلاش‌های ناموفق، حساب شما موقتاً قفل شده است.';
    }

    public function httpStatus(): int
    {
        return 423;
    }

    public function details(): array
    {
        return [
            'locked_until' => $this->lockedUntil,
            'seconds_remaining' => $this->secondsRemaining,
        ];
    }
}
