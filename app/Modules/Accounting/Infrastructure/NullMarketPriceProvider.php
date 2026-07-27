<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Accounting\Contracts\MarketPriceProvider;

/**
 * Default binding: there is no price feed wired in.
 *
 * Returning null is the honest answer, and every caller of MarketPriceProvider
 * is required to handle it — an unrealised gain computed against a zero price
 * would be a silently wrong number on a screen a trader acts upon.
 */
final class NullMarketPriceProvider implements MarketPriceProvider
{
    public function currentPricePerFineGram(): ?int
    {
        return null;
    }

    public function closingPricePerFineGram(string $date): ?int
    {
        return null;
    }
}
