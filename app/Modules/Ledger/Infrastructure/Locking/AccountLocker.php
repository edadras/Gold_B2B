<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Locking;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\Exceptions\LedgerAccountNotFoundException;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * docs/06-backend-laravel/02-implementation-guide.md §2.3.
 *
 * Every multi-account operation acquires its locks here so two operations
 * touching the same accounts can never take them in opposite orders
 * (AGENT_BRIEF rule 4, docs/03-domain/03-ledger.md §3.10 «جلوگیری از Deadlock»).
 *
 * All locking goes through lockAccounts(): the ids are resolved first with an
 * unlocked read, sorted, then locked with `WHERE id IN (...) ORDER BY id`, which
 * InnoDB serves from the primary key and therefore locks in ascending id order.
 * Locking directly by organization_id would let the optimiser walk a secondary
 * index and take the rows in some other order.
 */
final class AccountLocker
{
    /**
     * Lock the given accounts, ascending by id.
     *
     * @return Collection<int, LedgerAccountModel> keyed by account id
     */
    public function lockAccounts(int ...$accountIds): Collection
    {
        $this->assertInTransaction();

        $accountIds = array_values(array_unique($accountIds));
        sort($accountIds);   // ◄── the whole point of this class

        if ($accountIds === []) {
            return new Collection;
        }

        return LedgerAccountModel::query()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Lock every account of one organisation for one asset, keyed by bucket.
     *
     * Operations lock the organisation's whole asset set rather than just the
     * two buckets they touch, so a reserve (AVAILABLE→RESERVED) and a release
     * (RESERVED→AVAILABLE) on the same organisation are serialised against each
     * other instead of racing for the two balance rows in opposite orders.
     *
     * @return Collection<string, LedgerAccountModel> keyed by bucket
     */
    public function lockOrganizationAsset(int $organizationId, AssetType $asset): Collection
    {
        return $this->lockAccounts(...$this->accountIdsOf($asset, $organizationId))
            ->keyBy(fn (LedgerAccountModel $a): string => $a->bucket->value);
    }

    /**
     * Lock the accounts of several organisations at once. The ids are pooled
     * before sorting, so the lock order is global — locking org-by-org would
     * reintroduce the very deadlock this class prevents when two organisations'
     * id ranges interleave.
     *
     * @return Collection<int, Collection<string, LedgerAccountModel>> keyed by organization_id, then bucket
     */
    public function lockOrganizations(AssetType $asset, int ...$organizationIds): Collection
    {
        $ids = $this->accountIdsOf($asset, ...$organizationIds);

        return $this->lockAccounts(...$ids)
            ->groupBy(fn (LedgerAccountModel $a): int => $a->organization_id)
            ->map(fn (Collection $accounts): Collection => $accounts
                ->keyBy(fn (LedgerAccountModel $a): string => $a->bucket->value));
    }

    /**
     * Pull one bucket out of a locked set, or fail loudly.
     *
     * @param  Collection<string, LedgerAccountModel>  $accounts
     */
    public function require(
        Collection $accounts,
        int $organizationId,
        AssetType $asset,
        Bucket $bucket,
    ): LedgerAccountModel {
        $account = $accounts->get($bucket->value);

        if (! $account instanceof LedgerAccountModel) {
            throw new LedgerAccountNotFoundException($organizationId, $asset, $bucket);
        }

        return $account;
    }

    /**
     * Member account ids for the given organisations and asset.
     *
     * System accounts (system_account_code IS NOT NULL) are excluded: they are
     * addressed individually by code, not swept up by organisation.
     *
     * @return array<int, int>
     */
    private function accountIdsOf(AssetType $asset, int ...$organizationIds): array
    {
        return LedgerAccountModel::query()
            ->whereIn('organization_id', array_values(array_unique($organizationIds)))
            ->where('asset_type', $asset->value)
            ->whereNull('system_account_code')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Account locks are only meaningful inside a transaction');
        }
    }
}
