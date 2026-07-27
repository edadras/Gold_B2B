<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure;

use App\Modules\Dispute\Contracts\TradeParties;
use App\Modules\Dispute\Contracts\TradePartiesProvider;

/**
 * Default binding: this deployment cannot look up trades.
 *
 * Returning null makes DisputeService refuse to open a trade-linked dispute,
 * which is the safe direction. The check being enforced is "you were party to
 * this trade"; answering "I don't know" must not be treated as "yes".
 */
final class NullTradePartiesProvider implements TradePartiesProvider
{
    public function forTrade(int $tradeId): ?TradeParties
    {
        return null;
    }
}
