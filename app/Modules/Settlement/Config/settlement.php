<?php

declare(strict_types=1);

/*
 * Settlement's own parameters, merged under goldb2b.settlement.
 *
 * mergeConfigFrom lets the values already in config/goldb2b.php win, so the
 * platform defaults there (deadline hours, penalty rate, penalty cap,
 * completion window, grace) stay authoritative and only the keys this module
 * adds appear here. Operators change the business ones at runtime through the
 * system_settings table (AGENT_BRIEF rule 6); these are the bootstrap values.
 */
return [
    // §5.5 escalation ladder, in hours past the deadline.
    'penalty_after_hours' => 2,
    'operator_alert_after_hours' => 6,
    'default_after_hours' => 24,
    'full_suspension_after_hours' => 72,

    // §5.3 pattern 2 guards on a declared-but-unconfirmed payment.
    'confirm_reminder_hours' => 2,
    'confirm_operator_alert_hours' => 4,
    'confirm_auto_dispute_hours' => 24,

    // §5.4 — option A (proportional) is the documented system default.
    'partial_mode' => 'PROPORTIONAL',

    // §5.6 — the end-of-day netting window.
    'netting' => [
        'collect_at' => '17:35',
        'accept_deadline_at' => '18:00',
        // ADR-009: a batch is never executed without unanimous acceptance, and
        // there is no "auto accept on deadline". This flag only decides whether
        // an expired batch is cancelled by the sweep or left for an operator.
        'cancel_expired_batches' => true,
        // §5.6 confines multilateral netting to phase 3, conditional on legal
        // sign-off and guarantee capital for the clearing account.
        'multilateral_enabled' => env('SETTLEMENT_MULTILATERAL_NETTING', false),
    ],

    // Worked example 6 default: the penalty goes to the injured party, not the
    // platform. §5.5 flags this as a business/legal decision.
    'penalty_beneficiary' => 'COUNTERPARTY',

    /*
     * Implementation of App\Modules\Settlement\Contracts\LotMovementPort.
     *
     * Custody exposes no write contract, so Settlement cannot legally reach its
     * LotOwnershipService (AGENT_BRIEF rule 7). NullLotMovementPort is the
     * default: the ledger still moves the gold, but lot ownership does not
     * follow. Point this at a real adapter — or have Custody publish a write
     * contract — to complete the picture.
     */
    'lot_movement_port' => env('SETTLEMENT_LOT_MOVEMENT_PORT'),
];
