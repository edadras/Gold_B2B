<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Check 1 of §11.4. The reason is deliberately generic to the member when the
 * restriction came from AML — §12.10 forbids tipping off.
 */
final class TradingNotAllowedException extends DomainException
{
    public function __construct(public readonly ?string $reason = null)
    {
        parent::__construct('TRADING_NOT_ALLOWED');
    }

    public function errorCode(): string
    {
        return 'TRADING_NOT_ALLOWED';
    }

    public function userMessage(): string
    {
        return 'در حال حاضر امکان معامله برای شما وجود ندارد.';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['reason' => $this->reason];
    }
}
