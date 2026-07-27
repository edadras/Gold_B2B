<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure;

use App\Modules\Settlement\Contracts\TradeReaderInterface;
use App\Modules\Settlement\Contracts\TradeSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real trade reader, over Trading's own tables.
 *
 * Settlement's primary input is the TradeExecuted event, which carries every
 * figure a settlement needs, so nothing was visibly broken while this resolved
 * to NullTradeReader. What was broken is the secondary path: opening a
 * settlement for a trade whose event was missed — a queue that dropped it, a
 * listener that threw, a settlement reversed and reopened. That path asked
 * "does this trade exist and is it settleable?", was told "no" about every
 * trade in the database, and quietly did nothing.
 *
 * Read by table name rather than through Trading's models: this is a handful of
 * scalars into a DTO Settlement already owns, and the Schema guard keeps a
 * Trading-less deployment booting with exactly the old Null behaviour.
 */
final class EloquentTradeReader implements TradeReaderInterface
{
    /**
     * Trade statuses that may still be settled.
     *
     * SETTLED is excluded so the secondary path cannot open a second settlement
     * over a trade that already has one. REVERSED and DISPUTED are excluded
     * because both mean a human has taken charge of the outcome.
     *
     * @var list<string>
     */
    private const SETTLEABLE = ['EXECUTED', 'SETTLING'];

    public function find(int $tradeId): ?TradeSnapshot
    {
        $trade = $this->row($tradeId);

        if ($trade === null) {
            return null;
        }

        return new TradeSnapshot(
            tradeId: (int) $trade->id,
            buyerOrganizationId: (int) $trade->buyer_organization_id,
            sellerOrganizationId: (int) $trade->seller_organization_id,
            quantityFineMg: (int) $trade->quantity_fine_mg,
            grossAmountRial: (int) $trade->gross_amount_rial,
            buyerFeeRial: (int) $trade->buyer_fee_rial,
            sellerFeeRial: (int) $trade->seller_fee_rial,
            settlementType: (string) $trade->settlement_type,
            settlementDeadline: $trade->settlement_deadline === null
                ? null
                : (string) $trade->settlement_deadline,
            pricePerGramRial: (int) $trade->price_per_gram_rial,
        );
    }

    public function isSettleable(int $tradeId): bool
    {
        $trade = $this->row($tradeId);

        if ($trade === null) {
            return false;
        }

        // A trade that already carries a settlement id is not settleable again,
        // whatever its status says. Two settlements over one trade would lock
        // the obligation twice and each would demand its own payment.
        if ($trade->settlement_id !== null) {
            return false;
        }

        return in_array((string) $trade->status, self::SETTLEABLE, true);
    }

    private function row(int $tradeId): ?object
    {
        if (! Schema::hasTable('trades')) {
            return null;
        }

        return DB::table('trades')->where('id', $tradeId)->first();
    }
}
