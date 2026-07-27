<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\FlowFacts;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Reporting\Infrastructure\Models\DailyOrgSummaryModel;
use App\Modules\Reporting\Infrastructure\Models\DailyPlatformSummaryModel;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly rollup of §15.9.
 *
 * The invariant it must assert on every row:
 *
 *     gold_closing = gold_opening + bought + deposited
 *                  − sold − withdrawn + adjustment
 *
 *     «اگر برقرار نبود ► is_reconciled = FALSE + هشدار»
 *
 * The row is written whether or not the invariant holds. Refusing to write it
 * would leave a gap in the series on precisely the day something went wrong,
 * and the gap would be discovered much later than the flag; recording the
 * discrepancy and its size makes the bad day visible the next morning and
 * leaves the evidence in place.
 */
final readonly class DailySummaryBuilder
{
    /** Labels the flow source is expected to use, so the rollup can split them. */
    private const BOUGHT = 'خریدها';

    private const DEPOSITED = 'دریافت‌ها (سپرده)';

    private const SOLD = 'فروش‌ها';

    private const WITHDRAWN = 'تحویل‌ها (برداشت)';

    public function __construct(private ReportingDataSource $data) {}

    /**
     * Build one organisation's summary for one date.
     *
     * Idempotent: re-running a date overwrites its row, so a failed nightly run
     * can simply be repeated.
     */
    public function buildFor(int $organizationId, string $date): DailyOrgSummaryModel
    {
        $range = DateRange::day($date);

        $gold = $this->data->goldFlow($organizationId, $range);
        $rial = $this->data->rialFlow($organizationId, $range);
        $trades = $this->data->trades($organizationId, $range);
        $pnl = $this->data->pnl($organizationId, $range);

        $bought = $gold->inflow(self::BOUGHT);
        $deposited = $gold->inflow(self::DEPOSITED);
        $sold = $gold->outflow(self::SOLD);
        $withdrawn = $gold->outflow(self::WITHDRAWN);
        $adjustment = $gold->totalAdjustments();

        // Anything the source labelled differently still has to be counted, or
        // the invariant would fail for a naming mismatch rather than a real error.
        $otherIn = IntMath::sub($gold->totalInflows(), IntMath::add($bought, $deposited));
        $otherOut = IntMath::sub($gold->totalOutflows(), IntMath::add($sold, $withdrawn));

        $bought = IntMath::add($bought, $otherIn);
        $sold = IntMath::add($sold, $otherOut);

        // The report's own arithmetic …
        $expectedClosing = $this->invariant(
            $gold->opening,
            $bought,
            $deposited,
            $sold,
            $withdrawn,
            $adjustment,
        );

        // … against an independent read of the same balance.
        $independentGold = $this->data->closingGoldBalance($organizationId, $date);
        $independentRial = $this->data->closingRialBalance($organizationId, $date);

        $goldDiscrepancy = $independentGold === null
            ? 0
            : IntMath::sub($expectedClosing, $independentGold);

        $rialClosing = $rial->computedClosing();
        $rialDiscrepancy = $independentRial === null
            ? 0
            : IntMath::sub($rialClosing, $independentRial);

        $reconciled = $independentGold !== null
            && $independentRial !== null
            && $goldDiscrepancy === 0
            && $rialDiscrepancy === 0;

        $buys = array_filter($trades, static fn (TradeRow $t): bool => $t->isBuy());

        $attributes = [
            'gold_opening_mg' => $gold->opening,
            'gold_bought_mg' => max(0, $bought),
            'gold_sold_mg' => max(0, $sold),
            'gold_deposited_mg' => max(0, $deposited),
            'gold_withdrawn_mg' => max(0, $withdrawn),
            'gold_adjustment_mg' => $adjustment,
            'gold_closing_mg' => $expectedClosing,

            'rial_opening' => $rial->opening,
            'rial_in' => max(0, $rial->totalInflows()),
            'rial_out' => max(0, $rial->totalOutflows()),
            'rial_fees' => $this->feeTotal($organizationId, $range),
            'rial_closing' => $rialClosing,

            'trade_count' => count($trades),
            'buy_count' => count($buys),
            'sell_count' => count($trades) - count($buys),

            'realized_pnl' => $pnl->netOperatingProfit(),
            'avg_cost_per_gram' => 0,
            'closing_market_value' => max(0, $pnl->closingMarketValue),

            'is_reconciled' => $reconciled,
            'gold_discrepancy_mg' => $goldDiscrepancy,
            'rial_discrepancy' => $rialDiscrepancy,
            'computed_at' => Carbon::now(),
        ];

        DailyOrgSummaryModel::query()->updateOrInsert(
            ['organization_id' => $organizationId, 'summary_date' => $date],
            $attributes,
        );

        /** @var DailyOrgSummaryModel $row */
        $row = DailyOrgSummaryModel::query()
            ->where('organization_id', $organizationId)
            ->where('summary_date', $date)
            ->first();

        return $row;
    }

    /**
     * Build every active organisation for a date, then the platform rollup.
     *
     * @return array{organizations: int, unreconciled: int, failures: array<int, string>}
     */
    public function buildAll(string $date): array
    {
        $organizations = $this->data->activeOrganizations($date);

        $unreconciled = 0;
        $failures = [];

        foreach ($organizations as $organizationId) {
            try {
                $row = $this->buildFor((int) $organizationId, $date);

                if (! $row->is_reconciled) {
                    $unreconciled++;
                }
            } catch (\Throwable $e) {
                // One member's bad data must not stop every other member's
                // summary from being built.
                $failures[] = sprintf('org %d: %s', $organizationId, $e->getMessage());
            }
        }

        $this->buildPlatform($date, $unreconciled);

        return [
            'organizations' => count($organizations),
            'unreconciled' => $unreconciled,
            'failures' => $failures,
        ];
    }

    /**
     * The §15.9 invariant, in one place so the builder and its test are
     * checking the same expression rather than two similar ones.
     */
    public function invariant(
        int $opening,
        int $bought,
        int $deposited,
        int $sold,
        int $withdrawn,
        int $adjustment,
    ): int {
        $in = IntMath::add($opening, IntMath::add($bought, $deposited));
        $out = IntMath::add($sold, $withdrawn);

        return IntMath::add(IntMath::sub($in, $out), $adjustment);
    }

    /** Whether a stored row still satisfies the invariant it was written with. */
    public function holdsFor(DailyOrgSummaryModel $row): bool
    {
        return $row->gold_closing_mg === $this->invariant(
            $row->gold_opening_mg,
            $row->gold_bought_mg,
            $row->gold_deposited_mg,
            $row->gold_sold_mg,
            $row->gold_withdrawn_mg,
            $row->gold_adjustment_mg,
        );
    }

    /** Derived from the member rows, never recomputed from the ledger. */
    private function buildPlatform(string $date, int $unreconciled): void
    {
        $totals = DB::table('daily_org_summary')
            ->where('summary_date', $date)
            ->selectRaw(
                'COUNT(*) AS orgs, '
                .'SUM(CASE WHEN trade_count > 0 THEN 1 ELSE 0 END) AS trading, '
                .'COALESCE(SUM(gold_bought_mg + gold_sold_mg), 0) AS traded_mg, '
                .'COALESCE(SUM(trade_count), 0) AS trades, '
                .'COALESCE(SUM(gold_deposited_mg), 0) AS deposited, '
                .'COALESCE(SUM(gold_withdrawn_mg), 0) AS withdrawn, '
                .'COALESCE(SUM(gold_closing_mg), 0) AS held, '
                .'COALESCE(SUM(rial_fees), 0) AS fees'
            )
            ->first();

        DailyPlatformSummaryModel::query()->updateOrInsert(
            ['summary_date' => $date],
            [
                'active_org_count' => (int) ($totals->orgs ?? 0),
                'trading_org_count' => (int) ($totals->trading ?? 0),
                'gold_traded_mg' => (int) ($totals->traded_mg ?? 0),
                'rial_traded' => 0,
                'trade_count' => (int) ($totals->trades ?? 0),
                'gold_deposited_mg' => (int) ($totals->deposited ?? 0),
                'gold_withdrawn_mg' => (int) ($totals->withdrawn ?? 0),
                'gold_held_mg' => (int) ($totals->held ?? 0),
                'fee_income_rial' => (int) ($totals->fees ?? 0),
                'unreconciled_orgs' => $unreconciled,
                'is_reconciled' => $unreconciled === 0,
                'computed_at' => Carbon::now(),
            ],
        );
    }

    private function feeTotal(int $organizationId, DateRange $range): int
    {
        return max(0, IntMath::sum(array_map(
            static fn (object $fee): int => (int) $fee->amountRial,
            $this->data->fees($organizationId, $range),
        )));
    }
}
