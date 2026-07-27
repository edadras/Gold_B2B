<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A sale was reported for more fine gold than the cost-basis ledger knows about.
 *
 * Distinct from the ledger's InsufficientBalanceException: the real asset may
 * well exist, this says the accounting inventory is out of step and posting a
 * COGS figure from it would be a fabricated number.
 */
final class InsufficientInventoryException extends DomainException
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $requestedMg,
        public readonly int $availableMg,
    ) {
        parent::__construct(
            "Cost basis for organisation {$organizationId} holds {$availableMg} mg, "
            ."sale of {$requestedMg} mg requested"
        );
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_COST_BASIS';
    }

    public function userMessage(): string
    {
        return 'موجودی حسابداری طلا برای ثبت این فروش کافی نیست.';
    }

    /** @return array<string, int> */
    public function details(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'requested_mg' => $this->requestedMg,
            'available_mg' => $this->availableMg,
        ];
    }
}
