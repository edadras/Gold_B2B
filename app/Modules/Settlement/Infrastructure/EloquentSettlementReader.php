<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure;

use App\Modules\Settlement\Contracts\SettlementReaderInterface;
use App\Modules\Settlement\Contracts\SettlementSnapshot;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;

/**
 * The one place settlements leave this module, and they leave as snapshots.
 */
final class EloquentSettlementReader implements SettlementReaderInterface
{
    public function find(int $settlementId): ?SettlementSnapshot
    {
        return SettlementModel::query()->find($settlementId)?->toSnapshot();
    }

    public function findByCode(string $settlementCode): ?SettlementSnapshot
    {
        return SettlementModel::query()
            ->where('settlement_code', $settlementCode)
            ->first()
            ?->toSnapshot();
    }

    public function forTrade(int $tradeId): array
    {
        return SettlementModel::query()
            ->where('trade_id', $tradeId)
            ->orderBy('id')
            ->get()
            ->map(static fn (SettlementModel $s): SettlementSnapshot => $s->toSnapshot())
            ->all();
    }

    public function openForOrganization(int $organizationId): array
    {
        return SettlementModel::query()
            ->open()
            ->forOrganization($organizationId)
            ->orderBy('deadline_at')
            ->get()
            ->map(static fn (SettlementModel $s): SettlementSnapshot => $s->toSnapshot())
            ->all();
    }

    public function hasOpenSettlements(int $organizationId): bool
    {
        return SettlementModel::query()
            ->open()
            ->forOrganization($organizationId)
            ->exists();
    }
}
