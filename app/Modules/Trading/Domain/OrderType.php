<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * MARKET or LIMIT.
 *
 * §4.2 of the domain document also names RFQ_QUOTE, but an RFQ quote is not a
 * book order — it lives in rfq_quotes with its own lifecycle — so it is not a
 * member here, matching the ENUM in docs/04-data/02-schema-mysql.md §2.4.
 */
enum OrderType: string
{
    case MARKET = 'MARKET';
    case LIMIT = 'LIMIT';

    public function requiresPrice(): bool
    {
        return $this === self::LIMIT;
    }

    /** A market order crosses whatever is resting, subject to its slippage cap. */
    public function crossesUnconditionally(): bool
    {
        return $this === self::MARKET;
    }
}
