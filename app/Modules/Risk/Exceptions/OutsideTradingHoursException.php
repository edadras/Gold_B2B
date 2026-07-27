<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** Check 11 of §11.4. */
final class OutsideTradingHoursException extends DomainException
{
    public function __construct(
        public readonly string $at,
        public readonly string $opensAt,
        public readonly string $closesAt,
    ) {
        parent::__construct('OUTSIDE_TRADING_HOURS');
    }

    public function errorCode(): string
    {
        return 'OUTSIDE_TRADING_HOURS';
    }

    public function userMessage(): string
    {
        return 'خارج از ساعات مجاز معامله هستید.';
    }

    public function details(): array
    {
        return [
            'at' => $this->at,
            'opensAt' => $this->opensAt,
            'closesAt' => $this->closesAt,
        ];
    }
}
