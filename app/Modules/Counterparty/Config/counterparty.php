<?php

declare(strict_types=1);

/*
 * Counterparty module defaults, merged into `goldb2b.counterparty` by the
 * module provider. Kept inside the module rather than appended to
 * config/goldb2b.php so that the module owns its own knobs and nothing outside
 * app/Modules/Counterparty has to change when one is added.
 *
 * Operators override any of these per environment; the concentration
 * thresholds are the ones from docs/03-domain/10-counterparty.md §10.6.
 */
return [
    // Share of total receivable held with a single counterparty, in basis points.
    'concentration_warning_bps' => 4_000,
    'concentration_serious_bps' => 6_000,

    // How many pair rows the reconcile command loads at a time.
    'reconcile_chunk' => 500,

    // A balance confirmation left unanswered this long is stale; the requester
    // is expected to chase it rather than assume agreement.
    'confirmation_response_hours' => 72,
];
