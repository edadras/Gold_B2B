<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\LotRow;
use App\Modules\Admin\Contracts\VaultAdminPort;
use App\Modules\Admin\Contracts\VaultRow;

/**
 * Vault inventory (§1.8, «VaultInventory»). Read-only: moving physical gold is
 * a Custody operation with its own dual control and its own photographs.
 */
final class VaultInventoryService
{
    public function __construct(private readonly VaultAdminPort $vaults) {}

    /** @return list<VaultRow> */
    public function vaults(): array
    {
        return $this->vaults->vaults();
    }

    /**
     * @param  list<string>  $statuses
     * @return list<LotRow>
     */
    public function lots(?int $vaultId = null, array $statuses = [], int $limit = 200): array
    {
        return $this->vaults->lots($vaultId, $statuses, $limit);
    }

    /** @return array{declared: int, lots: int, difference: int} */
    public function reconcile(int $vaultId): array
    {
        return $this->vaults->reconcileVault($vaultId);
    }
}
