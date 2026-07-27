<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Contracts\FeeRow;
use App\Modules\Reporting\Contracts\FlowFacts;
use App\Modules\Reporting\Contracts\InventoryRow;
use App\Modules\Reporting\Contracts\PnlFacts;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Domain\DateRange;

/**
 * Default binding: nothing is wired up yet.
 *
 * Every report still renders, with zeros. The reconciliation still runs and
 * still passes, because a zero closing balance genuinely does reconcile against
 * zero movements — so the checking machinery is exercised in a default install
 * rather than lying dormant until the first real data source appears.
 *
 * The balance readers return 0 rather than null on purpose: null is reserved
 * for "the ledger is there but the read failed", which is a real problem worth
 * flagging, while this class means "there is no ledger here at all".
 */
final class NullReportingDataSource implements ReportingDataSource
{
    public function goldFlow(int $organizationId, DateRange $range): FlowFacts
    {
        return FlowFacts::empty();
    }

    public function rialFlow(int $organizationId, DateRange $range): FlowFacts
    {
        return FlowFacts::empty();
    }

    public function closingGoldBalance(int $organizationId, string $asOfDate): ?int
    {
        return 0;
    }

    public function closingRialBalance(int $organizationId, string $asOfDate): ?int
    {
        return 0;
    }

    /** @return array<int, TradeRow> */
    public function trades(int $organizationId, DateRange $range): array
    {
        return [];
    }

    public function pnl(int $organizationId, DateRange $range): PnlFacts
    {
        return PnlFacts::empty();
    }

    /** @return array<int, InventoryRow> */
    public function inventory(int $organizationId): array
    {
        return [];
    }

    /** @return array<int, FeeRow> */
    public function fees(int $organizationId, DateRange $range): array
    {
        return [];
    }

    /** @return array<int, int> */
    public function activeOrganizations(string $date): array
    {
        return [];
    }

    public function organizationName(int $organizationId): ?string
    {
        return null;
    }
}
