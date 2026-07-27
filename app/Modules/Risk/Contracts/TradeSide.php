<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/** Direction of the intent being risk-checked. */
enum TradeSide: string
{
    case BUY = 'BUY';
    case SELL = 'SELL';

    public function isSell(): bool
    {
        return $this === self::SELL;
    }
}
