<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests\Feature;

use App\Modules\Reporting\Application\DailySummaryBuilder;
use App\Modules\Reporting\Contracts\FlowFacts;
use App\Modules\Reporting\Contracts\TradeRow;
use App\Modules\Reporting\Tests\ReportingTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §15.9's invariant:
 *
 *     gold_closing = gold_opening + bought + deposited
 *                  − sold − withdrawn + adjustment
 */
final class DailySummaryBuilderTest extends ReportingTestCase
{
    private const ORG = 184;

    private const DATE = '2026-01-20';

    private DailySummaryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = $this->app->make(DailySummaryBuilder::class);
    }

    #[Test]
    public function the_summary_invariant_holds_for_a_normal_day(): void
    {
        $this->givenADay();

        $row = $this->builder->buildFor(self::ORG, self::DATE);

        self::assertSame(1_000_000, $row->gold_opening_mg);
        self::assertSame(250_000, $row->gold_bought_mg);
        self::assertSame(100_000, $row->gold_deposited_mg);
        self::assertSame(150_000, $row->gold_sold_mg);
        self::assertSame(50_000, $row->gold_withdrawn_mg);
        self::assertSame(-180, $row->gold_adjustment_mg);

        // 1,000,000 + 250,000 + 100,000 − 150,000 − 50,000 − 180
        self::assertSame(1_149_820, $row->gold_closing_mg);

        self::assertTrue($this->builder->holdsFor($row), 'the stored row must satisfy the invariant');
        self::assertTrue($row->is_reconciled);
        self::assertSame(0, $row->gold_discrepancy_mg);
    }

    #[Test]
    public function a_day_that_does_not_reconcile_is_still_recorded_but_flagged(): void
    {
        $this->givenADay();

        // The ledger disagrees by one gram.
        $this->data->goldBalanceOverride = 1_148_820;

        $row = $this->builder->buildFor(self::ORG, self::DATE);

        self::assertFalse($row->is_reconciled, 'the row must admit it does not reconcile');
        self::assertSame(1_000, $row->gold_discrepancy_mg);

        // The row exists: a gap in the series would hide the bad day.
        self::assertSame(
            1,
            DB::table('daily_org_summary')
                ->where('organization_id', self::ORG)
                ->where('summary_date', self::DATE)
                ->count(),
        );

        // And the arithmetic of the row itself is still internally consistent.
        self::assertTrue($this->builder->holdsFor($row));
    }

    #[Test]
    public function movements_under_unrecognised_labels_are_still_counted(): void
    {
        // A source that labels its buckets differently must not break the
        // invariant — the total has to be conserved whatever the labels say.
        $this->data->gold = new FlowFacts(
            opening: 500_000,
            inflows: ['از OTC' => 40_000, 'از RFQ' => 10_000],
            outflows: ['در Order Book' => 20_000],
        );

        $row = $this->builder->buildFor(self::ORG, self::DATE);

        self::assertSame(50_000, $row->gold_bought_mg);
        self::assertSame(20_000, $row->gold_sold_mg);
        self::assertSame(530_000, $row->gold_closing_mg);
        self::assertTrue($this->builder->holdsFor($row));
        self::assertTrue($row->is_reconciled);
    }

    #[Test]
    public function rebuilding_a_date_overwrites_rather_than_duplicating(): void
    {
        $this->givenADay();

        $this->builder->buildFor(self::ORG, self::DATE);
        $this->builder->buildFor(self::ORG, self::DATE);
        $row = $this->builder->buildFor(self::ORG, self::DATE);

        self::assertSame(
            1,
            DB::table('daily_org_summary')
                ->where('organization_id', self::ORG)
                ->where('summary_date', self::DATE)
                ->count(),
        );

        self::assertSame(1_149_820, $row->gold_closing_mg);
    }

    #[Test]
    public function trade_counts_come_from_the_trade_list(): void
    {
        $this->givenADay();

        $this->data->trades = [
            $this->trade(1, 'BUY'),
            $this->trade(2, 'BUY'),
            $this->trade(3, 'SELL'),
        ];

        $row = $this->builder->buildFor(self::ORG, self::DATE);

        self::assertSame(3, $row->trade_count);
        self::assertSame(2, $row->buy_count);
        self::assertSame(1, $row->sell_count);
    }

    #[Test]
    public function building_all_organisations_also_builds_the_platform_row(): void
    {
        $this->givenADay();
        $this->data->organizations = [self::ORG, 209];

        $result = $this->builder->buildAll(self::DATE);

        self::assertSame(2, $result['organizations']);
        self::assertSame(0, $result['unreconciled']);
        self::assertSame([], $result['failures']);

        $platform = DB::table('daily_platform_summary')->where('summary_date', self::DATE)->first();

        self::assertNotNull($platform);
        self::assertSame(2, (int) $platform->active_org_count);
        self::assertSame(0, (int) $platform->unreconciled_orgs);
        self::assertSame(1, (int) $platform->is_reconciled);
    }

    #[Test]
    public function an_unreconciled_member_makes_the_platform_row_unreconciled_too(): void
    {
        $this->givenADay();
        $this->data->organizations = [self::ORG];
        $this->data->goldBalanceOverride = 999;

        $result = $this->builder->buildAll(self::DATE);

        self::assertSame(1, $result['unreconciled']);

        $platform = DB::table('daily_platform_summary')->where('summary_date', self::DATE)->first();

        self::assertSame(1, (int) $platform->unreconciled_orgs);
        self::assertSame(0, (int) $platform->is_reconciled);
    }

    #[Test]
    public function the_invariant_helper_matches_the_documented_expression(): void
    {
        self::assertSame(
            1_149_820,
            $this->builder->invariant(1_000_000, 250_000, 100_000, 150_000, 50_000, -180),
        );

        // A positive adjustment moves it the other way.
        self::assertSame(
            1_150_180,
            $this->builder->invariant(1_000_000, 250_000, 100_000, 150_000, 50_000, 180),
        );
    }

    private function givenADay(): void
    {
        $this->data->gold = new FlowFacts(
            opening: 1_000_000,
            inflows: ['خریدها' => 250_000, 'دریافت‌ها (سپرده)' => 100_000],
            outflows: ['فروش‌ها' => 150_000, 'تحویل‌ها (برداشت)' => 50_000],
            adjustments: ['افت ذوب' => -180],
        );

        $this->data->rial = new FlowFacts(
            opening: 4_200_000_000,
            inflows: ['فروش' => 12_000_000_000],
            outflows: ['خرید' => 9_500_000_000],
        );
    }

    private function trade(int $id, string $side): TradeRow
    {
        return new TradeRow(
            tradeId: $id,
            executedOn: self::DATE,
            side: $side,
            venue: 'ORDER_BOOK',
            fineMg: 50_000,
            purityX10k: 9_950,
            pricePerFineGram: 78_480_000,
            grossRial: 3_924_000_000,
            feeRial: 5_886_000,
        );
    }
}
