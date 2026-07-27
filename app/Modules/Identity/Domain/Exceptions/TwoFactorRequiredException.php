<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The password was correct but the second factor is still outstanding.
 *
 * Carries a short-lived challenge id; no token is issued until the challenge is
 * answered (docs/02-architecture/04-security.md §4.2 step 3).
 */
final class TwoFactorRequiredException extends DomainException
{
    public function __construct(
        public readonly string $challengeId,
        public readonly string $method = 'TOTP',
    ) {
        parent::__construct('TWO_FACTOR_REQUIRED');
    }

    public function errorCode(): string
    {
        return 'TWO_FACTOR_REQUIRED';
    }

    public function userMessage(): string
    {
        return 'برای ادامه، کد تأیید دومرحله‌ای را وارد کنید.';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function details(): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'method' => $this->method,
        ];
    }
}
