<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\LotRow;
use App\Modules\Admin\Contracts\VaultAdminPort;
use App\Modules\Admin\Contracts\VaultRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vault inventory.
 *
 * Custody publishes `GoldLotRepositoryInterface` and `LotAllocatorInterface`,
 * both write-side ports; a whole-vault inventory read is not among them.
 *
 * A lot's location is its `vault_box_id`, and `vault_boxes` denormalises
 * `vault_id`, so one join places every lot in a vault.
 */
final class TableVaultAdminAdapter implements VaultAdminPort
{
    /** @return list<VaultRow> */
    public function vaults(): array
    {
        if (! Schema::hasTable('vaults')) {
            return [];
        }

        $rows = DB::table('vaults')->orderBy('id')->get();
        $inventory = $this->inventoryByVault();

        return array_map(static fn (object $row): VaultRow => new VaultRow(
            id: (int) $row->id,
            vaultCode: (string) $row->vault_code,
            name: (string) $row->name,
            status: (string) $row->status,
            capacityFineMg: $row->capacity_fine_mg === null ? null : (int) $row->capacity_fine_mg,
            storedFineMg: $inventory[(int) $row->id]['fine_mg'] ?? 0,
            lotCount: $inventory[(int) $row->id]['lots'] ?? 0,
            coverageExpiresAt: $row->coverage_expires_at === null
                ? null
                : (string) $row->coverage_expires_at,
        ), $rows->all());
    }

    /** @return list<LotRow> */
    public function lots(?int $vaultId = null, array $statuses = [], int $limit = 200): array
    {
        if (! Schema::hasTable('gold_lots')) {
            return [];
        }

        $query = DB::table('gold_lots as l')->select('l.*');

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as o', 'o.id', '=', 'l.owner_organization_id')
                ->addSelect('o.display_name as owner_name');
        }

        if ($vaultId !== null && $this->vaultChainPresent()) {
            $query->join('vault_boxes as b', 'b.id', '=', 'l.vault_box_id')
                ->where('b.vault_id', $vaultId);
        }

        if ($statuses !== []) {
            $query->whereIn('l.status', $statuses);
        }

        $rows = $query->orderByDesc('l.id')->limit($limit)->get();

        return array_map(static fn (object $row): LotRow => new LotRow(
            id: (int) $row->id,
            lotCode: (string) $row->lot_code,
            ownerOrganizationId: $row->owner_organization_id === null
                ? null
                : (int) $row->owner_organization_id,
            ownerName: isset($row->owner_name) && $row->owner_name !== null
                ? (string) $row->owner_name
                : null,
            status: (string) $row->status,
            grossWeightMg: (int) $row->gross_weight_mg,
            purityX10: (int) $row->purity_x10,
            fineWeightMg: (int) $row->fine_weight_mg,
            custodianType: (string) $row->custodian_type,
            vaultBoxId: $row->vault_box_id === null ? null : (int) $row->vault_box_id,
            physicalLocation: $row->physical_location === null ? null : (string) $row->physical_location,
        ), $rows->all());
    }

    public function countPendingOperations(): int
    {
        if (! Schema::hasTable('custody_operations')) {
            return 0;
        }

        return (int) DB::table('custody_operations')->where('status', 'REQUESTED')->count();
    }

    /** @return array{declared: int, lots: int, difference: int} */
    public function reconcileVault(int $vaultId): array
    {
        $declared = Schema::hasTable('vaults')
            ? (int) (DB::table('vaults')->where('id', $vaultId)->value('capacity_fine_mg') ?? 0)
            : 0;

        $inventory = $this->inventoryByVault();
        $held = $inventory[$vaultId]['fine_mg'] ?? 0;

        return [
            'declared' => $declared,
            'lots' => $held,
            'difference' => $held - $declared,
        ];
    }

    /** @return array<int, array{fine_mg: int, lots: int}> */
    private function inventoryByVault(): array
    {
        if (! Schema::hasTable('gold_lots') || ! $this->vaultChainPresent()) {
            return [];
        }

        $rows = DB::table('gold_lots as l')
            ->join('vault_boxes as b', 'b.id', '=', 'l.vault_box_id')
            ->whereNotIn('l.status', ['WITHDRAWN', 'CONSUMED'])
            ->selectRaw('b.vault_id, COALESCE(SUM(l.fine_weight_mg), 0) AS fine_mg, COUNT(*) AS lots')
            ->groupBy('b.vault_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->vault_id] = [
                'fine_mg' => (int) $row->fine_mg,
                'lots' => (int) $row->lots,
            ];
        }

        return $out;
    }

    /**
     * `vault_boxes` carries `vault_id` directly as well as the shelf/safe
     * chain, so one join answers "which vault is this lot in?".
     */
    private function vaultChainPresent(): bool
    {
        return Schema::hasTable('vault_boxes');
    }
}
