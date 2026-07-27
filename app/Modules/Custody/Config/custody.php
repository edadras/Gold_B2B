<?php

declare(strict_types=1);

/*
 * Custody module configuration.
 *
 * Merged into config('goldb2b.custody.*') by CustodyServiceProvider so the
 * module owns its own constants without editing the shared config file.
 * Operator-tunable business parameters belong in `system_settings`; the values
 * here are bootstrap defaults and the constants that must never drift.
 */
return [
    'codes' => [
        'lot_prefix' => 'GL-',
        'assay_prefix' => 'AS-',
        'waybill_prefix' => 'WB-',
        'audit_prefix' => 'VA-',
        'pad_length' => 8,
    ],

    'lineage' => [
        // Hard guard against cycles in the recursive CTE (docs §2.7).
        'max_depth' => 50,
    ],

    'split' => [
        'max_children' => 50,
    ],

    'loss' => [
        // Above this share of the input fine weight an operation needs an
        // extra approval (docs/03-domain/06-custody-vault.md §6.5).
        'extra_approval_bps' => 50,   // 0.5%
    ],

    'reconciliation' => [
        // docs/03-domain/06-custody-vault.md §6.6 thresholds, in basis points
        // of the expected weight. 1 bps = 0.01%.
        'tolerance_bps' => 1,   // < 0.01%  -> scale noise, record only
        'review_bps' => 10,     // 0.01%..0.1% -> review + approved adjustment
        // > review_bps -> full investigation, vault frozen
    ],

    'withdrawal' => [
        'code_ttl_minutes' => 120,
        'code_digits' => 8,
    ],

    'qr' => [
        'token_bytes' => 32,   // 256-bit
        'public_url_template' => 'https://goldb2b.ir/v/%s',
    ],
];
