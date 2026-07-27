<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Readers;

use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TradeStatus;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * F18 exposure figures for Risk's checks 6, 7 and 9, replacing Risk's null
 * implementation.
 *
 * Exposure has two halves and Trading owns both of them:
 *
 *   · **unsettled** — trades that have executed but not yet settled. The metal
 *     or the money is promised and gone from the member's free capacity even
 *     though no ledger bucket has moved yet.
 *   · **reserved by open orders** — the outstanding part of each resting
 *     order's reservation, which is exactly `outstandingReservation()`, not the
 *     original reservation: the part already claimed by a fill is counted in
 *     the unsettled half and would otherwise be double-counted.
 *
 * These are the expensive calls in the pre-trade gate, which is why Risk runs
 * them last.
 */
final class EloquentTradingExposureReader implements TradingExposureReaderInterface
{
    /** Statuses of a trade that has executed but not yet finished settling. */
    private const UNSETTLED = [
        TradeStatus::EXECUTED->value,
        TradeStatus::SETTLING->value,
        TradeStatus::DISPUTED->value,
    ];

    public function openGoldExposureMg(int $organizationId): int
    {
        $undelivered = (int) Trade::query()
            ->where('seller_organization_id', $organizationId)
            ->whereIn('status', self::UNSETTLED)
            ->sum('quantity_fine_mg');

        return $undelivered + $this->reservedByOpenOrders($organizationId, Side::SELL);
    }

    public function openRialExposure(int $organizationId): int
    {
        $unpaid = (int) Trade::query()
            ->where('buyer_organization_id', $organizationId)
            ->whereIn('status', self::UNSETTLED)
            ->sum('buyer_net_rial');

        return $unpaid + $this->reservedByOpenOrders($organizationId, Side::BUY);
    }

    public function openOrderCount(int $organizationId): int
    {
        return Order::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', OrderStatus::matchable())
            ->count();
    }

    public function counterpartyDailyVolumeMg(int $organizationId, int $counterpartyOrgId): int
    {
        $start = CarbonImmutable::now()->startOfDay();

        return (int) Trade::query()
            ->whereBetween('executed_at', [$start, $start->endOfDay()])
            ->where(static function (Builder $q) use ($organizationId, $counterpartyOrgId): void {
                $q->where(static function (Builder $inner) use ($organizationId, $counterpartyOrgId): void {
                    $inner->where('buyer_organization_id', $organizationId)
                        ->where('seller_organization_id', $counterpartyOrgId);
                })->orWhere(static function (Builder $inner) use ($organizationId, $counterpartyOrgId): void {
                    $inner->where('buyer_organization_id', $counterpartyOrgId)
                        ->where('seller_organization_id', $organizationId);
                });
            })
            ->sum('quantity_fine_mg');
    }

    /** Σ of what each resting order of this side still has locked. */
    private function reservedByOpenOrders(int $organizationId, Side $side): int
    {
        return (int) Order::query()
            ->where('organization_id', $organizationId)
            ->where('side', $side->value)
            ->whereIn('status', OrderStatus::matchable())
            ->sum(DB::raw('reserved_amount - consumed_amount - released_amount'));
    }
}
