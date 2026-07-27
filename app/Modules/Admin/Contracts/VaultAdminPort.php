<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

interface VaultAdminPort
{
    /** @return list<VaultRow> */
    public function vaults(): array;

    /**
     * @param  list<string>  $statuses
     * @return list<LotRow>
     */
    public function lots(?int $vaultId = null, array $statuses = [], int $limit = 200): array;

    public function countPendingOperations(): int;

    /**
     * Declared fine weight held in a vault versus the sum of its lots — the
     * physical counterpart of a ledger discrepancy.
     *
     * @return array{declared: int, lots: int, difference: int}
     */
    public function reconcileVault(int $vaultId): array;
}
