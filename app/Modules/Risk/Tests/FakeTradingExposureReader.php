<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Contracts\TradingExposureReaderInterface;

/** Controllable stand-in for the Trading/Settlement aggregates. */
final class FakeTradingExposureReader implements TradingExposureReaderInterface
{
    public function __construct(
        public int $goldMg = 0,
        public int $rial = 0,
        public int $openOrders = 0,
        public int $counterpartyVolumeMg = 0,
    ) {}

    public function openGoldExposureMg(int $organizationId): int
    {
        return $this->goldMg;
    }

    public function openRialExposure(int $organizationId): int
    {
        return $this->rial;
    }

    public function openOrderCount(int $organizationId): int
    {
        return $this->openOrders;
    }

    public function counterpartyDailyVolumeMg(int $organizationId, int $counterpartyOrgId): int
    {
        return $this->counterpartyVolumeMg;
    }
}
