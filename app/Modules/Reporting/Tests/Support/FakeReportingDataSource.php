<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests\Support;

use App\Modules\Reporting\Contracts\FeeRow;
use App\Modules\Reporting\Contracts\FlowFacts;
use App\Modules\Reporting\Contracts\InventoryRow;
use App\Modules\Reporting\Contracts\PnlFacts;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Domain\DateRange;

/**
 * A data source a test can steer.
 *
 * The important capability is `$goldBalanceOverride`: it lets a test make the
 * independent ledger read disagree with the movement rows on purpose, which is
 * the only way to prove the §15.7 reconciliation actually detects a discrepancy
 * rather than comparing a number with itself.
 */
final class FakeReportingDataSource implements ReportingDataSource
{
    public FlowFacts $gold;

    public FlowFacts $rial;

    /** When set, returned instead of the honest closing gold balance. */
    public ?int $goldBalanceOverride = null;

    public ?int $rialBalanceOverride = null;

    /** When true, the ledger read fails outright. */
    public bool $goldBalanceUnavailable = false;

    public bool $rialBalanceUnavailable = false;

    /** @var array<int, TradeRow> */
    public array $trades = [];

    /** @var array<int, InventoryRow> */
    public array $inventory = [];

    /** @var array<int, FeeRow> */
    public array $fees = [];

    public PnlFacts $pnl;

    /** @var array<int, int> */
    public array $organizations = [];

    public function __construct()
    {
        $this->gold = FlowFacts::empty();
        $this->rial = FlowFacts::empty();
        $this->pnl = PnlFacts::empty();
    }

    public function goldFlow(int $organizationId, DateRange $range): FlowFacts
    {
        return $this->gold;
    }

    public function rialFlow(int $organizationId, DateRange $range): FlowFacts
    {
        return $this->rial;
    }

    public function closingGoldBalance(int $organizationId, string $asOfDate): ?int
    {
        if ($this->goldBalanceUnavailable) {
            return null;
        }

        return $this->goldBalanceOverride ?? $this->gold->computedClosing();
    }

    public function closingRialBalance(int $organizationId, string $asOfDate): ?int
    {
        if ($this->rialBalanceUnavailable) {
            return null;
        }

        return $this->rialBalanceOverride ?? $this->rial->computedClosing();
    }

    /** @return array<int, TradeRow> */
    public function trades(int $organizationId, DateRange $range): array
    {
        return $this->trades;
    }

    public function pnl(int $organizationId, DateRange $range): PnlFacts
    {
        return $this->pnl;
    }

    /** @return array<int, InventoryRow> */
    public function inventory(int $organizationId): array
    {
        return $this->inventory;
    }

    /** @return array<int, FeeRow> */
    public function fees(int $organizationId, DateRange $range): array
    {
        return $this->fees;
    }

    /** @return array<int, int> */
    public function activeOrganizations(string $date): array
    {
        return $this->organizations;
    }

    public function organizationName(int $organizationId): ?string
    {
        return 'طلافروشی کریمی';
    }
}
