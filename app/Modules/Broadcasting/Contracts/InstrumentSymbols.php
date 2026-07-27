<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Contracts;

/**
 * instrument id -> instrument code ("GOLD-995-T0").
 *
 * WHY THIS PORT EXISTS. Domain events carry `instrumentId`, but §3.2 names the
 * public channel after the *code*: `market.GOLD-995-T0`. The natural source is
 * Trading's instrument table, and Pricing already declares an equivalent port
 * (Pricing\Contracts\InstrumentDirectory) that Trading binds an adapter for.
 * Broadcasting may depend only on Shared and Identity, so it cannot import
 * Pricing's port either, and Trading is not this module's to edit — hence a
 * second, deliberately tiny port with its own adapter.
 *
 * A resolver that cannot answer returns null and the frame is dropped rather
 * than published on a guessed channel name: `market.7` would be a channel no
 * client subscribes to, and worse, a channel name that leaks an internal id.
 */
interface InstrumentSymbols
{
    public function codeFor(int $instrumentId): ?string;
}
