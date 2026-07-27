<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/**
 * The aggregate figures behind checks 6, 7 and 9 of §11.4. Trading and
 * Settlement provide them; Risk never queries their tables.
 *
 * These are the expensive calls, which is why they run last.
 */
interface TradingExposureReaderInterface
{
    /** F18 — unsettled deliveries plus gold reserved by open sell orders. */
    public function openGoldExposureMg(int $organizationId): int;

    /** F18 — unpaid obligations plus rial reserved by open buy orders. */
    public function openRialExposure(int $organizationId): int;

    public function openOrderCount(int $organizationId): int;

    /** Fine milligrams traded with one counterparty today. */
    public function counterpartyDailyVolumeMg(int $organizationId, int $counterpartyOrgId): int;
}
