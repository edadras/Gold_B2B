<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Deliberately says nothing about *which* half was wrong, and is thrown for an
 * unknown mobile just as for a wrong password, so the endpoint cannot be used
 * to enumerate registered members.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct(public readonly int $remainingAttempts = 0)
    {
        parent::__construct('INVALID_CREDENTIALS');
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }

    public function userMessage(): string
    {
        return 'شماره موبایل یا رمز عبور نادرست است.';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function details(): array
    {
        return ['remaining_attempts' => $this->remainingAttempts];
    }
}
