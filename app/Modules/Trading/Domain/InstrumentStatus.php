<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/** Whether an instrument may be traded at all (docs/03-domain/04-trading.md §4.1). */
enum InstrumentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case CLOSED = 'CLOSED';

    public function isTradable(): bool
    {
        return $this === self::ACTIVE;
    }
}
