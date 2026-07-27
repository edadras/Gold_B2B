<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

final readonly class AccountingPeriodClosed
{
    public function __construct(
        public int $organizationId,
        public string $periodCode,
        public string $startsOn,
        public string $endsOn,
        public int $closingDebitRial,
        public int $closingCreditRial,
        public int $closingFineMg,
    ) {}
}
