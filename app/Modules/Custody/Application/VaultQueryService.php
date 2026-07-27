<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\VaultModel;

/**
 * The read side of the vault endpoints.
 *
 * ADDED FOR THE HTTP LAYER. VaultService owns writes — every method on it opens
 * a transaction and takes pessimistic locks — and four of the §2.10 endpoints
 * are pure reads that would otherwise have become Eloquent queries in a
 * controller. Keeping them here also records the one non-obvious fact about the
 * schema: a deposit and a withdrawal are not two tables. They are both rows in
 * `custody_operations`, told apart by `operation_type` VAULT_IN / VAULT_OUT,
 * which is why `GET /vault/deposits` and `GET /vault/withdrawals` differ by a
 * single enum value.
 */
final class VaultQueryService
{
    /**
     * @return list<CustodyOperationModel> newest first
     */
    public function depositsFor(int $organizationId, int $limit = 100): array
    {
        return $this->operationsFor($organizationId, CustodyOperationType::VAULT_IN, $limit);
    }

    /**
     * @return list<CustodyOperationModel> newest first
     */
    public function withdrawalsFor(int $organizationId, int $limit = 100): array
    {
        return $this->operationsFor($organizationId, CustodyOperationType::VAULT_OUT, $limit);
    }

    public function findOperation(int $operationId): ?CustodyOperationModel
    {
        /** @var CustodyOperationModel|null */
        return CustodyOperationModel::query()->find($operationId);
    }

    /**
     * Vaults currently accepting deposits.
     *
     * PLATFORM REFERENCE DATA, NOT TENANT DATA. Vaults belong to the operator,
     * not to any member: every organisation sees the same list, so there is no
     * organisation id to scope by and no cross-tenant question to answer. The
     * authorisation on the route is therefore a role check only — the second
     * leg has nothing to compare against. Nothing member-specific (holdings,
     * box assignments, occupancy) may ever be added to this payload for exactly
     * that reason: it would turn shared reference data into a leak.
     *
     * @return list<VaultModel>
     */
    public function vaultsAcceptingDeposits(): array
    {
        return VaultModel::query()
            ->where('status', 'ACTIVE')
            ->orderBy('vault_code')
            ->get()
            ->all();
    }

    /** @return list<CustodyOperationModel> */
    private function operationsFor(int $organizationId, CustodyOperationType $type, int $limit): array
    {
        return CustodyOperationModel::query()
            ->where('organization_id', $organizationId)
            ->where('operation_type', $type->value)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}
