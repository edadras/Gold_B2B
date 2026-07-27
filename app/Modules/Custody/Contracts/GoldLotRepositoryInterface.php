<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts;

use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Custody\Domain\Enums\LotStatus;

/**
 * Read access to gold lots for the rest of the platform.
 *
 * Returns snapshots only — no Eloquent models cross the module boundary.
 */
interface GoldLotRepositoryInterface
{
    public function find(int $lotId): ?GoldLotSnapshot;

    public function findByCode(string $lotCode): ?GoldLotSnapshot;

    public function findByQrToken(string $qrToken): ?GoldLotSnapshot;

    /**
     * @param  list<int>  $lotIds
     * @return list<GoldLotSnapshot> in ascending id order
     */
    public function findMany(array $lotIds): array;

    /** @return list<GoldLotSnapshot> */
    public function forOwner(int $ownerOrganizationId, ?LotStatus $status = null): array;

    /** Σ fine_weight_mg of the owner's lots in the given status (default AVAILABLE). */
    public function totalFineMgForOwner(int $ownerOrganizationId, ?LotStatus $status = null): int;

    /** @return list<GoldLotSnapshot> lots currently held in a vault, any owner */
    public function inVault(int $vaultId): array;

    public function isOwnedBy(int $lotId, int $organizationId): bool;
}
