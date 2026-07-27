<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\NettingBatchModel;
use App\Modules\Settlement\Infrastructure\Models\NettingPositionModel;
use App\Modules\Settlement\Infrastructure\Models\PaymentModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementEventModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of Settlement, for the list and detail screens.
 *
 * Every query is scoped with `forOrganization()`, which matches any of the four
 * party columns — a settlement has a gold deliverer, a gold receiver, a cash
 * payer and a cash receiver, and in a netted batch those are not the same two
 * organisations. Filtering on `organization_id` alone would be wrong as well as
 * unsafe: there is no such column.
 */
final class SettlementQueryService
{
    /** @return Collection<int, SettlementModel> */
    public function forOrganization(
        int $organizationId,
        ?SettlementStatus $status = null,
        ?int $beforeId = null,
        int $limit = 50,
    ): Collection {
        $query = SettlementModel::query()
            ->forOrganization($organizationId)
            ->orderByDesc('id')
            ->limit($limit);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        /** @var Collection<int, SettlementModel> */
        return $query->get();
    }

    /**
     * Settlements waiting on THIS member to do something.
     *
     * Which states count depends on which side the member is on: the cash payer
     * acts on PAYMENT_PENDING, the cash receiver on PAYMENT_DECLARED, and the
     * gold deliverer once payment has cleared. Returning "everything open"
     * would put the counterparty's homework on the member's to-do list.
     *
     * @return Collection<int, SettlementModel>
     */
    public function pendingFor(int $organizationId, int $limit = 100): Collection
    {
        /** @var Collection<int, SettlementModel> */
        return SettlementModel::query()
            ->forOrganization($organizationId)
            ->where(function ($q) use ($organizationId): void {
                $q->where(function ($inner) use ($organizationId): void {
                    $inner->where('cash_payer_org_id', $organizationId)
                        ->whereIn('status', [
                            SettlementStatus::PAYMENT_PENDING->value,
                            SettlementStatus::OVERDUE->value,
                        ]);
                })->orWhere(function ($inner) use ($organizationId): void {
                    $inner->where('cash_receiver_org_id', $organizationId)
                        ->where('status', SettlementStatus::PAYMENT_DECLARED->value);
                })->orWhere(function ($inner) use ($organizationId): void {
                    $inner->where('gold_deliverer_org_id', $organizationId)
                        ->whereIn('status', [
                            SettlementStatus::PAYMENT_CONFIRMED->value,
                            SettlementStatus::GOLD_TRANSFERRING->value,
                        ]);
                });
            })
            ->orderBy('deadline_at')
            ->limit($limit)
            ->get();
    }

    /** Null when the settlement is missing OR the caller is not one of its parties. */
    public function find(int $settlementId, int $organizationId): ?SettlementModel
    {
        /** @var SettlementModel|null */
        return SettlementModel::query()
            ->whereKey($settlementId)
            ->forOrganization($organizationId)
            ->first();
    }

    /** @return Collection<int, SettlementEventModel> */
    public function events(int $settlementId): Collection
    {
        /** @var Collection<int, SettlementEventModel> */
        return SettlementEventModel::query()
            ->where('settlement_id', $settlementId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /** The latest declaration on a settlement, which is what the screens show. */
    public function latestPayment(int $settlementId): ?PaymentModel
    {
        /** @var PaymentModel|null */
        return PaymentModel::query()
            ->where('settlement_id', $settlementId)
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, NettingBatchModel> */
    public function nettingBatchesFor(int $organizationId, int $limit = 50): Collection
    {
        $batchIds = NettingPositionModel::query()
            ->where('organization_id', $organizationId)
            ->pluck('batch_id');

        /** @var Collection<int, NettingBatchModel> */
        return NettingBatchModel::query()
            ->whereIn('id', $batchIds)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * A batch is only visible to a member that has a position in it — that is
     * what makes "another organisation's batch" a 404 rather than a 403.
     */
    public function nettingBatch(int $batchId, int $organizationId): ?NettingBatchModel
    {
        $hasPosition = NettingPositionModel::query()
            ->where('batch_id', $batchId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $hasPosition) {
            return null;
        }

        /** @var NettingBatchModel|null */
        return NettingBatchModel::query()->find($batchId);
    }

    public function positionIn(int $batchId, int $organizationId): ?NettingPositionModel
    {
        /** @var NettingPositionModel|null */
        return NettingPositionModel::query()
            ->where('batch_id', $batchId)
            ->where('organization_id', $organizationId)
            ->first();
    }
}
