<?php

declare(strict_types=1);

namespace App\Modules\Risk\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** Check 8 of §11.4. */
final class SettlementTypeNotAllowedException extends DomainException
{
    /** @param list<string> $allowed */
    public function __construct(
        public readonly string $settlementType,
        public readonly array $allowed = [],
    ) {
        parent::__construct('SETTLEMENT_TYPE_NOT_ALLOWED');
    }

    public function errorCode(): string
    {
        return 'SETTLEMENT_TYPE_NOT_ALLOWED';
    }

    public function userMessage(): string
    {
        return 'این نوع تسویه برای سطح ریسک شما مجاز نیست.';
    }

    public function details(): array
    {
        return [
            'settlementType' => $this->settlementType,
            'allowed' => $this->allowed,
        ];
    }
}
