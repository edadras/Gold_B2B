<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Shared\Contracts\ReferencePriceOracle;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of the ledger for `GET /balances` and `GET /ledger/*`.
 *
 * The write services (LedgerService and friends) are transaction owners with
 * pessimistic locking; nothing here takes a lock, because a balance screen must
 * never be able to block a trade. Reads come off the maintained
 * `ledger_balances` rows, which the write path keeps in step.
 *
 * Valuation goes through ReferencePriceOracle rather than Pricing directly:
 * Ledger may not depend on Pricing (module graph), and a missing price yields
 * null rather than a misleading zero.
 */
final readonly class BalanceQueryService
{
    public function __construct(
        private LedgerService $ledger,
        private ReferencePriceOracle $prices,
    ) {}

    /**
     * The combined summary of §2.3.
     *
     * @return array{gold: array<string, mixed>, rial: array<string, mixed>, as_of: string}
     */
    public function summary(int $organizationId): array
    {
        return [
            'gold' => $this->gold($organizationId),
            'rial' => $this->rial($organizationId),
            'as_of' => now()->toIso8601ZuluString('millisecond'),
        ];
    }

    /** @return array<string, mixed> */
    public function gold(int $organizationId): array
    {
        $buckets = $this->buckets($organizationId, AssetType::GOLD);

        $total = IntMath::sum(array_values($buckets));
        $price = $this->prices->pricePerFineGramRial();

        return [
            'metal_type' => 'GOLD',
            'available_mg' => $buckets[Bucket::AVAILABLE->value],
            'reserved_mg' => $buckets[Bucket::RESERVED->value],
            'in_settlement_mg' => $buckets[Bucket::IN_SETTLEMENT->value],
            'in_dispute_mg' => $buckets[Bucket::IN_DISPUTE->value],
            'total_mg' => $total,
            // F-series valuation: mg × rial-per-gram ÷ 1000, floored. Integer
            // division only — a float here would be a rounding bug in a money
            // figure the member reads as authoritative.
            'market_value_rial' => $price === null ? null : IntMath::mulDivFloor($total, $price, 1000),
            'price_per_fine_gram_rial' => $price,
        ];
    }

    /** @return array<string, mixed> */
    public function rial(int $organizationId): array
    {
        $buckets = $this->buckets($organizationId, AssetType::RIAL);

        return [
            'available' => $buckets[Bucket::AVAILABLE->value],
            'reserved' => $buckets[Bucket::RESERVED->value],
            'in_settlement' => $buckets[Bucket::IN_SETTLEMENT->value],
            'in_dispute' => $buckets[Bucket::IN_DISPUTE->value],
            'payable' => $buckets[Bucket::PAYABLE->value] ?? 0,
            'net' => IntMath::sum(array_values($buckets)),
        ];
    }

    /**
     * One page of ledger entries, newest first.
     *
     * Always filtered by organization_id — the column is denormalised onto
     * ledger_entries precisely so this query never has to join and never has to
     * trust a join to keep tenants apart.
     *
     * @param  int|null  $beforeId  cursor: return entries with a lower id
     * @return Collection<int, LedgerEntryModel>
     */
    public function entries(
        int $organizationId,
        AssetType $asset,
        ?Bucket $bucket = null,
        ?int $beforeId = null,
        int $limit = 50,
    ): Collection {
        $query = LedgerEntryModel::query()
            ->where('organization_id', $organizationId)
            ->where('asset_type', $asset->value)
            ->orderByDesc('id')
            ->limit($limit);

        if ($bucket !== null) {
            $query->whereIn('account_id', $this->accountIds($organizationId, $asset, $bucket));
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        /** @var Collection<int, LedgerEntryModel> */
        return $query->get();
    }

    /** A single entry, or null when it is missing or belongs to another member. */
    public function entry(int $entryId, int $organizationId): ?LedgerEntryModel
    {
        /** @var LedgerEntryModel|null */
        return LedgerEntryModel::query()
            ->where('id', $entryId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    /** @return array<string, int> bucket value => balance */
    private function buckets(int $organizationId, AssetType $asset): array
    {
        $balances = [];

        foreach (Bucket::forAsset($asset) as $bucket) {
            $balances[$bucket->value] = $this->ledger->rawBalance($organizationId, $asset, $bucket);
        }

        return $balances;
    }

    /** @return list<int> */
    private function accountIds(int $organizationId, AssetType $asset, Bucket $bucket): array
    {
        return LedgerAccountModel::query()
            ->where('organization_id', $organizationId)
            ->where('asset_type', $asset->value)
            ->where('bucket', $bucket->value)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
