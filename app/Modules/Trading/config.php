<?php

declare(strict_types=1);

/*
 * Module-local defaults, merged under goldb2b.trading.
 *
 * Values an operator changes at runtime belong in system_settings (fee rates,
 * market hours); what lives here is structural — how deep the book may be swept,
 * how long a negotiation may run. config/goldb2b.php always wins where both set
 * the same key.
 */
return [
    // "PAUSED به مدت ۱۵ دقیقه" — docs/03-domain/04-trading.md §4.8.
    'circuit_breaker_pause_minutes' => 15,

    // "حداکثر ۵ رفت‌وبرگشت، سپس انقضا" — §4.6.
    'otc_max_rounds' => 5,

    // Default validity windows, §4.6 and §4.7.
    'otc_offer_minutes' => 30,
    'rfq_minutes' => 15,

    // Slippage allowance applied to a MARKET order that names none (F10).
    'default_max_slippage_bps' => 50,
];
