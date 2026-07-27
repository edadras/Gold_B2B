<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure;

use App\Modules\Dispute\Contracts\TradeParties;
use App\Modules\Dispute\Contracts\TradePartiesProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real trade lookup, over Trading's own tables.
 *
 * Without it TradePartiesProvider resolved to the Null implementation, which
 * answers "I cannot vouch for that trade" for every trade — and DisputeService
 * correctly treats that as a refusal. The effect in production was that no
 * trade-linked dispute could be opened at all, and since almost every dispute
 * this platform models is about a trade (عیار, کسری وزن, عدم تحویل), the entire
 * module was unreachable. It passed its own suite because that suite installs a
 * stub provider.
 *
 * Queried by table name rather than through Trading's Eloquent models even
 * though the dependency graph permits the import, because this is a read of
 * four scalars and binding to Trading's model would drag its casts, its
 * accessors and its lifecycle into a module that wants none of them. The
 * Schema::hasTable guard keeps a Trading-less deployment booting: it degrades
 * to exactly the Null behaviour, which is the safe direction.
 *
 * Purity comes from the instrument rather than the trade — trades carry a fine
 * weight, and fineness is a property of what was traded. A dispute over عیار
 * compares the assayed purity against this declared one, so reading it from the
 * wrong place would put the claim's arithmetic on the wrong footing.
 */
final class EloquentTradePartiesProvider implements TradePartiesProvider
{
    public function forTrade(int $tradeId): ?TradeParties
    {
        if (! Schema::hasTable('trades')) {
            return null;
        }

        $trade = DB::table('trades')
            ->where('trades.id', $tradeId)
            ->leftJoin('instruments', 'instruments.id', '=', 'trades.instrument_id')
            ->select([
                'trades.id',
                'trades.buyer_organization_id',
                'trades.seller_organization_id',
                'trades.quantity_fine_mg',
                'trades.price_per_gram_rial',
                'trades.gross_amount_rial',
                'trades.settlement_id',
                'instruments.min_purity_x10',
            ])
            ->first();

        if ($trade === null) {
            return null;
        }

        return new TradeParties(
            tradeId: (int) $trade->id,
            buyerOrgId: (int) $trade->buyer_organization_id,
            sellerOrgId: (int) $trade->seller_organization_id,
            fineMg: (int) $trade->quantity_fine_mg,
            // `min_purity_x10` is already on the ×10,000 scale that
            // TradeParties wants — عیار ۹۹۵ is stored as 9950 and the table's
            // own CHECK caps it at 10000. The column name is a misnomer, so
            // this reads it straight rather than "converting" it twice.
            purityX10k: (int) ($trade->min_purity_x10 ?? 0),
            pricePerFineGram: (int) $trade->price_per_gram_rial,
            grossRial: (int) $trade->gross_amount_rial,
            settlementId: $trade->settlement_id === null ? null : (int) $trade->settlement_id,
            // Trades do not name a lot: which metal settles a trade is decided
            // by the allocation plan at settlement time, not at execution.
            goldLotId: null,
        );
    }
}
