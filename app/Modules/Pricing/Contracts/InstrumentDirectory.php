<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

/**
 * Resolves an instrument CODE to the id Pricing stores its rows against.
 *
 * Pricing keys quotes, candles and alerts on `instrument_id`, but the API
 * speaks in codes ("GOLD-995-T0") and the instruments table belongs to Trading.
 * Pricing may only depend on Shared, so it declares the port here and Trading
 * binds the adapter — the same inversion already used for
 * TradePrintSourceInterface.
 */
interface InstrumentDirectory
{
    public function idForCode(string $code): ?int;

    public function codeForId(int $instrumentId): ?string;

    /** @return list<int> ids of the instruments currently tradable */
    public function activeInstrumentIds(): array;
}
