<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;

/** Vault IN — docs/03-domain/06-custody-vault.md §6.3. */
final readonly class DepositCommand
{
    /** @param list<DepositPiece> $pieces */
    public function __construct(
        public int $vaultId,
        public int $ownerOrganizationId,
        public array $pieces,
        public int $requestedByUserId,
        public int $executedByUserId,
        public ?int $defaultVaultBoxId = null,
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
    ) {
        if ($pieces === []) {
            throw new InvalidLotOperationException('VAULT_IN', 'a deposit needs at least one piece');
        }
    }
}
