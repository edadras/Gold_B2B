<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Readers;

use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Contracts\TradeRecord;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trading's answer to Risk's TradeHistoryReaderInterface, replacing the null
 * implementation Risk shipped with.
 *
 * Everything crossing back is a TradeRecord — scalars only. The AML rules must
 * never see an Eloquent model, which is why they cannot simply query `trades`
 * themselves (AGENT_BRIEF rule 7).
 *
 * Every method is bounded by time, as the interface requires: recentTrades() in
 * particular feeds cycle detection over the whole platform and would otherwise
 * be an invitation to select the entire table.
 */
final class EloquentTradeHistoryReader implements TradeHistoryReaderInterface
{
    /** @return list<TradeRecord> */
    public function tradesFor(int $organizationId, CarbonInterface $since): array
    {
        return $this->hydrate(
            $this->base($since)->where(static function (Builder $q) use ($organizationId): void {
                $q->where('buyer_organization_id', $organizationId)
                    ->orWhere('seller_organization_id', $organizationId);
            })
        );
    }

    /** @return list<TradeRecord> */
    public function tradesBetween(int $organizationId, int $counterpartyOrgId, CarbonInterface $since): array
    {
        return $this->hydrate(
            $this->base($since)->where(static function (Builder $q) use ($organizationId, $counterpartyOrgId): void {
                $q->where(static function (Builder $inner) use ($organizationId, $counterpartyOrgId): void {
                    $inner->where('buyer_organization_id', $organizationId)
                        ->where('seller_organization_id', $counterpartyOrgId);
                })->orWhere(static function (Builder $inner) use ($organizationId, $counterpartyOrgId): void {
                    $inner->where('buyer_organization_id', $counterpartyOrgId)
                        ->where('seller_organization_id', $organizationId);
                });
            })
        );
    }

    /** @return list<TradeRecord> */
    public function recentTrades(CarbonInterface $since): array
    {
        return $this->hydrate($this->base($since));
    }

    public function dailyVolumeMg(int $organizationId, CarbonInterface $day): int
    {
        $start = CarbonImmutable::instance($day)->startOfDay();

        return (int) Trade::query()
            ->whereBetween('executed_at', [$start, $start->endOfDay()])
            ->where(static function (Builder $q) use ($organizationId): void {
                $q->where('buyer_organization_id', $organizationId)
                    ->orWhere('seller_organization_id', $organizationId);
            })
            ->sum('quantity_fine_mg');
    }

    /**
     * Mean daily volume over the preceding $days days, today excluded.
     *
     * Divides by the window length rather than by the number of days that
     * actually saw trading: a member who traded once in thirty days has a low
     * baseline, which is exactly what §12.9 wants to compare against.
     */
    public function averageDailyVolumeMg(int $organizationId, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $from = $today->subDays($days);

        $total = (int) Trade::query()
            ->where('executed_at', '>=', $from)
            ->where('executed_at', '<', $today)
            ->where(static function (Builder $q) use ($organizationId): void {
                $q->where('buyer_organization_id', $organizationId)
                    ->orWhere('seller_organization_id', $organizationId);
            })
            ->sum('quantity_fine_mg');

        return intdiv($total, $days);
    }

    /** @return Builder<Trade> */
    private function base(CarbonInterface $since): Builder
    {
        return Trade::query()
            ->where('executed_at', '>=', CarbonImmutable::instance($since))
            ->orderBy('executed_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Trade>  $query
     * @return list<TradeRecord>
     */
    private function hydrate(Builder $query): array
    {
        $records = [];

        // Selecting only the columns TradeRecord needs: the AML sweeps run over
        // thousands of rows and the fee columns are dead weight there.
        foreach ($query->get([
            'id',
            'buyer_organization_id',
            'seller_organization_id',
            'quantity_fine_mg',
            'price_per_gram_rial',
            'executed_at',
            'trade_source',
        ]) as $trade) {
            $records[] = new TradeRecord(
                id: $trade->id,
                buyerOrganizationId: $trade->buyer_organization_id,
                sellerOrganizationId: $trade->seller_organization_id,
                fineWeightMg: $trade->quantity_fine_mg,
                pricePerGramRial: $trade->price_per_gram_rial,
                executedAt: CarbonImmutable::instance($trade->executed_at),
                source: $trade->trade_source->value,
            );
        }

        return $records;
    }
}
