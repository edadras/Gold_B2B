<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml;

/**
 * The inbound events the rule engine reacts to. docs/03-domain/12-aml-compliance.md §12.2.
 *
 * TRADE_INTENT fires before execution, so a BLOCK rule can stop the trade;
 * TRADE_EXECUTED fires after and can only flag.
 */
enum AmlEventType: string
{
    case TRADE_INTENT = 'TRADE_INTENT';
    case TRADE_EXECUTED = 'TRADE_EXECUTED';
    case SETTLEMENT = 'SETTLEMENT';
    case DEPOSIT = 'DEPOSIT';
    case WITHDRAWAL = 'WITHDRAWAL';
    case ORDER_PLACED = 'ORDER_PLACED';

    public function isTrade(): bool
    {
        return $this === self::TRADE_INTENT
            || $this === self::TRADE_EXECUTED
            || $this === self::ORDER_PLACED;
    }

    /** Only pre-execution events can be stopped; the rest are flagged after the fact. */
    public function canBlock(): bool
    {
        return $this === self::TRADE_INTENT
            || $this === self::ORDER_PLACED
            || $this === self::WITHDRAWAL;
    }
}
