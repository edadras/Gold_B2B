<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * Lifecycle of a trade record. Trading only ever writes EXECUTED; the rest are
 * driven by Settlement and Dispute, which is why there is no state machine here
 * — the authoritative one lives in docs/03-domain/05-settlement.md.
 */
enum TradeStatus: string
{
    case EXECUTED = 'EXECUTED';
    case SETTLING = 'SETTLING';
    case SETTLED = 'SETTLED';
    case DISPUTED = 'DISPUTED';
    case REVERSED = 'REVERSED';
}
