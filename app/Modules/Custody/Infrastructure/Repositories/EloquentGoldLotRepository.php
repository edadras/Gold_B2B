<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Repositories;

use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;

/**
 * The only place other modules reach gold_lots from, and it hands back
 * snapshots so nothing outside Custody can mutate a lot.
 */
final class EloquentGoldLotRepository implements GoldLotRepositoryInterface
{
    public function find(int $lotId): ?GoldLotSnapshot
    {
        return GoldLotModel::query()->find($lotId)?->toSnapshot();
    }

    public function findByCode(string $lotCode): ?GoldLotSnapshot
    {
        return GoldLotModel::query()->where('lot_code', $lotCode)->first()?->toSnapshot();
    }

    public function findByQrToken(string $qrToken): ?GoldLotSnapshot
    {
        return GoldLotModel::query()->where('qr_token', $qrToken)->first()?->toSnapshot();
    }

    public function findMany(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return GoldLotModel::query()
            ->whereIn('id', $lotIds)
            ->orderBy('id')
            ->get()
            ->map(static fn (GoldLotModel $l): GoldLotSnapshot => $l->toSnapshot())
            ->values()
            ->all();
    }

    public function forOwner(int $ownerOrganizationId, ?LotStatus $status = null): array
    {
        $query = GoldLotModel::query()->where('owner_organization_id', $ownerOrganizationId);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->orderBy('id')
            ->get()
            ->map(static fn (GoldLotModel $l): GoldLotSnapshot => $l->toSnapshot())
            ->values()
            ->all();
    }

    public function totalFineMgForOwner(int $ownerOrganizationId, ?LotStatus $status = null): int
    {
        return (int) GoldLotModel::query()
            ->where('owner_organization_id', $ownerOrganizationId)
            ->where('status', ($status ?? LotStatus::AVAILABLE)->value)
            ->sum('fine_weight_mg');
    }

    public function inVault(int $vaultId): array
    {
        return GoldLotModel::query()
            ->where('custodian_type', CustodianType::VAULT->value)
            ->where('custodian_id', $vaultId)
            ->whereNotIn('status', [LotStatus::CONSUMED->value, LotStatus::WITHDRAWN->value])
            ->orderBy('id')
            ->get()
            ->map(static fn (GoldLotModel $l): GoldLotSnapshot => $l->toSnapshot())
            ->values()
            ->all();
    }

    public function isOwnedBy(int $lotId, int $organizationId): bool
    {
        return GoldLotModel::query()
            ->whereKey($lotId)
            ->where('owner_organization_id', $organizationId)
            ->exists();
    }
}
