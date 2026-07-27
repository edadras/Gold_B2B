<?php

declare(strict_types=1);

/*
 * Infrastructure-level configuration. Business parameters that operators change
 * at runtime (fee rates, limits, market hours) live in the system_settings
 * table instead; the values here are the bootstrap defaults and the constants
 * that must never drift.
 */
return [
    'market' => [
        'timezone' => 'Asia/Tehran',
        'open_time' => env('MARKET_OPEN_TIME', '09:00'),
        'close_time' => env('MARKET_CLOSE_TIME', '17:30'),
        'pre_open_time' => env('MARKET_PRE_OPEN_TIME', '08:45'),
    ],

    'ledger' => [
        'lock_timeout_seconds' => 10,
        'hash_chain_enabled' => env('LEDGER_HASH_CHAIN', true),
        'reconcile_hour' => 2,
        'snapshot_hour' => 3,
        'system_organization_id' => 0,
    ],

    'settlement' => [
        'default_deadline_hours' => 8,
        'overdue_check_minutes' => 10,
        'penalty_daily_x100k' => 50,
        'penalty_cap_x100k' => 10_000,
        'completion_window_hours' => 24,
        'default_grace_minutes' => 0,
    ],

    'fees' => [
        'default_taker_x100k' => 150,
        'default_maker_x100k' => 100,
    ],

    'pricing' => [
        'max_staleness_seconds' => 300,
        'circuit_breaker_bps' => 300,
        'max_order_deviation_bps' => 1_000,
        'hard_reject_deviation_bps' => 2_000,
    ],

    'risk' => [
        'new_member_max_order_mg' => 2_000_000,
        'new_member_daily_mg' => 10_000_000,
        'new_member_max_open_orders' => 20,
    ],

    'dispute' => [
        'reply_deadline_hours' => 24,
        'negotiation_hours' => 48,
    ],

    'units' => [
        'mg_per_gram' => 1_000,
        'mesghal_mg_x10' => 46_083,
        'ounce_mg_x10000' => 311_034_768,
        'purity_scale' => 10_000,
        'rate_scale' => 100_000,
        'bps_scale' => 10_000,
    ],

    'idempotency' => [
        'ttl_hours' => 24,
    ],
];
