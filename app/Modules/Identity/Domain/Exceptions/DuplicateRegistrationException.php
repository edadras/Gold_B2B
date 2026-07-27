<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Registration collided with an existing member — the "عدم ثبت‌نام تکراری"
 * automated check of docs/03-domain/01-identity-kyc.md §1.5.
 *
 * Raised before the insert so the caller gets a 409 with a field name instead
 * of a unique-key violation from the driver.
 */
final class DuplicateRegistrationException extends DomainException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('DUPLICATE_REGISTRATION');
    }

    public function errorCode(): string
    {
        return 'DUPLICATE_REGISTRATION';
    }

    public function userMessage(): string
    {
        return 'با این مشخصات قبلاً ثبت‌نام انجام شده است.';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['field' => $this->field];
    }
}
