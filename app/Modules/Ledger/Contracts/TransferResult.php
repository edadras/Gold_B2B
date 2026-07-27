<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\TransactionGroup;
use JsonSerializable;

/**
 * Outcome of a completed transfer between two organisations.
 *
 * Scalars plus the two typed ids: safe to hand to any module.
 */
final readonly class TransferResult implements JsonSerializable
{
    public function __construct(
        public TransactionGroup $group,
        public LedgerEntryId $debitEntryId,
        public LedgerEntryId $creditEntryId,
        public int $fromOrganizationId,
        public int $toOrganizationId,
        public int $amount,
        public string $assetType,
    ) {}

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return [
            'transaction_group' => $this->group->value,
            'debit_entry_id' => $this->debitEntryId->value,
            'credit_entry_id' => $this->creditEntryId->value,
            'from_organization_id' => $this->fromOrganizationId,
            'to_organization_id' => $this->toOrganizationId,
            'amount' => $this->amount,
            'asset_type' => $this->assetType,
        ];
    }
}
