<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure;

use App\Modules\Risk\Contracts\TradingExposureReaderInterface;

/**
 * Zero exposure until Trading and Settlement provide the real aggregates.
 * Reporting zero is safe: the ceilings still apply to the intent itself, so a
 * single oversized order is caught by check 4 regardless.
 */
final class NullTradingExposureReader implements TradingExposureReaderInterface
{
    public function openGoldExposureMg(int $organizationId): int
    {
        return 0;
    }

    public function openRialExposure(int $organizationId): int
    {
        return 0;
    }

    public function openOrderCount(int $organizationId): int
    {
        return 0;
    }

    public function counterpartyDailyVolumeMg(int $organizationId, int $counterpartyOrgId): int
    {
        return 0;
    }
}
