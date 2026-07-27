<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;

/** Vault OUT step 1 — docs/03-domain/06-custody-vault.md §6.4. */
final readonly class WithdrawalRequestCommand
{
    /** @param list<int> $lotIds */
    public function __construct(
        public int $vaultId,
        public int $ownerOrganizationId,
        public array $lotIds,
        public int $requestedByUserId,
        public ?string $receiverName = null,
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
    ) {
        if ($lotIds === []) {
            throw new InvalidLotOperationException('VAULT_OUT', 'select at least one lot to withdraw');
        }

        if (count(array_unique($lotIds)) !== count($lotIds)) {
            throw new InvalidLotOperationException('VAULT_OUT', 'the same lot was listed twice');
        }
    }

    /** @return list<int> ascending — the lock order. */
    public function orderedLotIds(): array
    {
        $ids = $this->lotIds;
        sort($ids);

        return array_values($ids);
    }
}
