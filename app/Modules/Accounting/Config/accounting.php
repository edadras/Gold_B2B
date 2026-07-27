<?php

declare(strict_types=1);

/*
 * Merged into config('goldb2b.accounting') by AccountingServiceProvider.
 *
 * The module's own defaults live here rather than in config/goldb2b.php so a
 * module can be added without editing a shared file; operators still override
 * through the usual config cascade.
 */
return [
    // Voucher generation runs off the trade transaction (§9.7 «ثبت ناهمگام»).
    'queue' => env('ACCOUNTING_QUEUE', 'accounting'),

    // Prefix of every voucher number: GB-1404-08-00142.
    'voucher_prefix' => 'GB',

    // Fiscal periods are per member (§9.7); this is only the default length
    // used when a period is opened without explicit dates.
    'default_period_months' => 1,

    // Rial per fine gram. Only ever used for the informational F17 figure, and
    // only when no MarketPriceProvider is bound; null means "report unknown".
    'fallback_market_price_per_gram' => null,
];
