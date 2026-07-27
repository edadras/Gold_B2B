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
 * Every multi-account operation acquires its locks here, in ascending account
 * id, so two operations touching the same pair of accounts can never take them
 * in opposite orders (AGENT_BRIEF rule 4, docs §3.10 "جلوگیری از Deadlock").
 */
final class AccountLocker
{
    /**
     * Lock the given accounts and return them keyed by id.
     *
     * @return Collection<int, LedgerAccountModel>
     */
    public function lockAccounts(int ...$accountIds): Collection
    {
        $this->assertInTransaction();

        $accountIds = array_values(array_unique($accountIds));
        sort($accountIds);   // ◄── the whole point of this class

        if ($accountIds === []) {
            return collect();
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
     * @return Collection<string, LedgerAccountModel>
     */
    public function lockOrganizationAsset(int $organizationId, AssetType $asset): Collection
    {
        $this->assertInTransaction();

        return LedgerAccountModel::query()
            ->where('organization_id', $organizationId)
            ->where('asset_type', $asset->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (LedgerAccountModel $a): string => $a->bucket->value);
    }

    /**
     * Lock the accounts of several organisations at once, ordered by account id
     * across the whole set — locking org-by-org would reintroduce the deadlock
     * this class exists to prevent when two orgs' id ranges interleave.
     *
     * @return Collection<int, Collection<string, LedgerAccountModel>> keyed by organization_id
     */
    public function lockOrganizations(AssetType $asset, int ...$organizationIds): Collection
    {
        $this->assertInTransaction();

        $organizationIds = array_values(array_unique($organizationIds));
        sort($organizationIds);

        return LedgerAccountModel::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('asset_type', $asset->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('organization_id')
            ->map(fn (Collection $accounts): Collection => $accounts
                ->keyBy(fn (LedgerAccountModel $a): string => $a->bucket->value));
    }

    /**
     * @param  Collection<string, LedgerAccountModel>  $accounts
     */
    public function require(Collection $accounts, int $organizationId, AssetType $asset, Bucket $bucket): LedgerAccountModel
    {
        $account = $accounts->get($bucket->value);

        if (! $account instanceof LedgerAccountModel) {
            throw new LedgerAccountNotFoundException($organizationId, $asset, $bucket);
        }

        return $account;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Account locks are only meaningful inside a transaction');
        }
    }
}
