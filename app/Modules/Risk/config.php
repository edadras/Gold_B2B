<?php

declare(strict_types=1);

/*
 * Risk defaults merged into config('goldb2b.risk') by RiskServiceProvider.
 * Anything already set in config/goldb2b.php wins.
 */
return [
    // Redis connection backing the daily counters (§11.5).
    'redis_connection' => 'default',

    // §11.4 check 9: share of the daily allowance any single counterparty may take.
    'counterparty_daily_share_bps' => 5_000,

    // §11.4 check 11. Turned off in fixtures that trade outside market hours.
    'enforce_trading_hours' => true,

    // §11.7: at most one margin call per member per window.
    'margin_call_throttle_seconds' => 300,

    // §11.8 automatic prerequisites.
    'limit_increase' => [
        'min_days_active' => 90,
        'min_settled_trades' => 50,
        'min_on_time_bps' => 9_800,
        'min_credit_score' => 700,
    ],
];
