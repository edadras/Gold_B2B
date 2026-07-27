<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Contracts;

/**
 * Read model over Trading, used for one purpose: proving that whoever is
 * opening a dispute was actually party to the trade.
 *
 * Returning null means "this module cannot vouch for that trade". Callers must
 * treat null as a refusal, not as permission — see DisputeService::open().
 */
interface TradePartiesProvider
{
    public function forTrade(int $tradeId): ?TradeParties;
}
