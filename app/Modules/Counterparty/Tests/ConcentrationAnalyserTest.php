<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\ConcentrationAnalyser;
use App\Modules\Counterparty\Domain\ConcentrationLevel;
use App\Modules\Counterparty\Domain\ExposureMetric;
use PHPUnit\Framework\Attributes\Test;

/** §10.6: warn at 40% of total receivable with one counterparty, serious at 60%. */
final class ConcentrationAnalyserTest extends CounterpartyTestCase
{
    private ConcentrationAnalyser $analyser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyser = $this->app->make(ConcentrationAnalyser::class);
    }

    #[Test]
    public function it_reproduces_the_worked_example_from_the_design_doc(): void
    {
        // 1,200 g / 350 g / 210 g → 68% / 20% / 12%.
        $this->relations->applyTrade(1, 201, 1_200_000, 0);
        $this->relations->applyTrade(1, 202, 350_000, 0);
        $this->relations->applyTrade(1, 203, 210_000, 0);

        $report = $this->analyser->analyse(1);

        self::assertSame(1_760_000, $report->totalReceivable);
        self::assertCount(3, $report->entries);
        self::assertSame(201, $report->entries[0]->counterpartyOrgId);
        self::assertSame(6_818, $report->topShareBps);
        self::assertSame(ConcentrationLevel::SERIOUS, $report->level);
        self::assertTrue($report->isAlarming());

        self::assertSame(1_988, $report->entries[1]->shareBps);
        self::assertSame(ConcentrationLevel::NORMAL, $report->entries[1]->level);
        self::assertCount(1, $report->alarmingEntries());
    }

    #[Test]
    public function forty_percent_is_a_warning_and_thirty_nine_is_not(): void
    {
        $this->relations->applyTrade(1, 201, 400_000, 0);
        $this->relations->applyTrade(1, 202, 600_000, 0);

        $report = $this->analyser->analyse(1);

        self::assertSame(6_000, $report->topShareBps);
        self::assertSame(ConcentrationLevel::SERIOUS, $report->level, '60% is exactly the serious threshold');
        self::assertSame(ConcentrationLevel::WARNING, $report->entries[1]->level, '40% is exactly the warning threshold');

        // Nudge the split to 39% / 61% and the smaller side drops to normal.
        $this->relations->applyTrade(1, 201, -10_000, 0);
        $this->relations->applyTrade(1, 202, 10_000, 0);

        $report = $this->analyser->analyse(1);

        self::assertSame(6_100, $report->topShareBps);
        self::assertSame(ConcentrationLevel::NORMAL, $report->entries[1]->level);
        self::assertSame(3_900, $report->entries[1]->shareBps);
    }

    #[Test]
    public function payables_do_not_offset_receivables(): void
    {
        // Owed 1,000 g by one house, owing 900 g to another: the concentration
        // risk is still 100% of the receivable, not 10% of a netted figure.
        $this->relations->applyTrade(1, 201, 1_000_000, 0);
        $this->relations->applyTrade(1, 202, -900_000, 0);

        $report = $this->analyser->analyse(1);

        self::assertSame(1_000_000, $report->totalReceivable);
        self::assertCount(1, $report->entries);
        self::assertSame(10_000, $report->topShareBps);
        self::assertSame(ConcentrationLevel::SERIOUS, $report->level);
    }

    #[Test]
    public function rial_exposure_is_measured_separately(): void
    {
        $this->relations->applyTrade(1, 201, 1_000_000, 1_000_000_000);
        $this->relations->applyTrade(1, 202, 0, 9_000_000_000);

        $gold = $this->analyser->analyse(1, ExposureMetric::GOLD);
        $rial = $this->analyser->analyse(1, ExposureMetric::RIAL);

        self::assertSame(10_000, $gold->topShareBps, 'all gold receivable sits with one member');
        self::assertSame(202, $rial->topCounterparty()?->counterpartyOrgId);
        self::assertSame(9_000, $rial->topShareBps);
    }

    #[Test]
    public function an_empty_book_is_not_concentrated(): void
    {
        $report = $this->analyser->analyse(999);

        self::assertSame(0, $report->totalReceivable);
        self::assertSame(0, $report->topShareBps);
        self::assertSame(ConcentrationLevel::NORMAL, $report->level);
        self::assertNull($report->topCounterparty());
    }

    #[Test]
    public function it_lists_every_member_whose_book_trips_a_threshold(): void
    {
        $this->relations->applyTrade(1, 201, 1_000_000, 0);   // 100% — serious
        $this->relations->applyTrade(2, 201, 100_000, 0);     // 50/50 — warning
        $this->relations->applyTrade(2, 202, 100_000, 0);
        $this->relations->applyTrade(3, 201, 100_000, 0);     // evenly spread
        $this->relations->applyTrade(3, 202, 100_000, 0);
        $this->relations->applyTrade(3, 203, 100_000, 0);

        $alarming = array_map(
            static fn ($r): int => $r->organizationId,
            $this->analyser->alarmingOrganizations(),
        );

        self::assertContains(1, $alarming);
        self::assertContains(2, $alarming);
        self::assertNotContains(3, $alarming, '33% each is below the warning threshold');
        // Worst first.
        self::assertSame(1, $alarming[0]);
    }
}
