<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Support;

use App\Modules\Dispute\Contracts\TradeParties;
use App\Modules\Dispute\Contracts\TradePartiesProvider;

/**
 * Stands in for Trading, which this module may not import.
 *
 * A trade that was never added returns null, which is exactly what the real
 * null provider does and what the "unknown trade" test relies on.
 */
final class StubTradePartiesProvider implements TradePartiesProvider
{
    /** @var array<int, TradeParties> */
    private array $trades = [];

    public function add(TradeParties $trade): void
    {
        $this->trades[$trade->tradeId] = $trade;
    }

    public function forTrade(int $tradeId): ?TradeParties
    {
        return $this->trades[$tradeId] ?? null;
    }
}
