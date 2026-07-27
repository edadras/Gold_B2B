<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class InvalidTwoFactorCodeException extends DomainException
{
    public function __construct()
    {
        parent::__construct('INVALID_TWO_FACTOR_CODE');
    }

    public function errorCode(): string
    {
        return 'INVALID_TWO_FACTOR_CODE';
    }

    public function userMessage(): string
    {
        return 'کد تأیید دومرحله‌ای نادرست یا منقضی است.';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
