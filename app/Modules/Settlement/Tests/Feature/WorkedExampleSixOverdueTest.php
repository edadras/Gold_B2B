<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Risk\Contracts\PenaltyCalculatorInterface;
use App\Modules\Settlement\Application\OverdueService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Events\SettlementDefaulted;
use App\Modules\Settlement\Events\SettlementOverdue;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/11-appendix/03-worked-examples.md, example 6 — the overdue timeline and
 * default, and §5.5's escalation ladder.
 *
 *   09:15  trade executed, deadline_at 17:00 the same day
 *   17:00  ⚠ deadline passes            → OVERDUE
 *   19:00  second reminder              → penalty accrual begins
 *   23:00  operator alerted             → new order entry suspended for 291
 *   next day 17:00  ⛔ 24 hours gone     → DEFAULTED
 *
 * F21 on the amount, one day late:
 *
 *   penalty = min(floor(19,620,000,000 × 50 × 1 / 100,000),
 *                 floor(19,620,000,000 × 10,000 / 100,000))
 *           = min(9,810,000, 1,962,000,000) = 9,810,000 rial
 */
final class WorkedExampleSixOverdueTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    private const FINE_MG = 250_000;

    private const GROSS_RIAL = 19_620_000_000;

    private const EXPECTED_PENALTY = 9_810_000;

    private const EXPECTED_CAP = 1_962_000_000;

    /** 09:15, as in the document's timeline. */
    private CarbonImmutable $tradedAt;

    /** 17:00 the same day. */
    private CarbonImmutable $deadline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tradedAt = CarbonImmutable::parse('2026-07-27T09:15:00Z');
        $this->deadline = CarbonImmutable::parse('2026-07-27T17:00:00Z');

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 1_000_000);
        $this->depositRial(self::BUYER, 25_000_000_000);
    }

    #[Test]
    public function f21_produces_the_documents_penalty_figure(): void
    {
        $penalties = $this->app->make(PenaltyCalculatorInterface::class);

        $this->assertSame(
            self::EXPECTED_PENALTY,
            $penalties->penalty(self::GROSS_RIAL, 1, 50, 10_000),
            'F21: 19,620,000,000 at 50 ×100k for one day = 9,810,000 rial',
        );

        $this->assertSame(
            self::EXPECTED_CAP,
            $penalties->cap(self::GROSS_RIAL, 10_000),
            'The 10 % cap is 1,962,000,000 and does not bite here',
        );

        $this->assertSame(
            self::EXPECTED_PENALTY,
            min($penalties->penalty(self::GROSS_RIAL, 1, 50, 10_000), $penalties->cap(self::GROSS_RIAL)),
        );
    }

    #[Test]
    public function the_escalation_ladder_climbs_one_rung_at_a_time(): void
    {
        Event::fake([SettlementOverdue::class, SettlementDefaulted::class]);

        $settlement = $this->openOverdueSettlement();
        $overdue = $this->app->make(OverdueService::class);

        // ── 15:00, two hours to go — nothing happens ─────────────────────────
        $report = $overdue->sweep($this->deadline->subHours(2));
        $this->assertSame(0, $report['checked']);
        $this->assertSame(SettlementStatus::PAYMENT_PENDING, $settlement->fresh()->status);

        // ── 17:00, T+0 — OVERDUE, no penalty yet ─────────────────────────────
        $report = $overdue->sweep($this->deadline);
        $this->assertSame(1, $report['checked']);
        $this->assertSame(1, $report['overdue']);
        $this->assertSame(0, $report['defaulted']);

        $fresh = $settlement->fresh();
        $this->assertSame(SettlementStatus::OVERDUE, $fresh->status);
        $this->assertNotNull($fresh->overdue_since);
        $this->assertSame(OverdueService::LEVEL_OVERDUE, $fresh->escalation_level);
        $this->assertSame(0, $fresh->penalty_rial, 'Penalty accrual only starts at T+2h');

        // ── 19:00, T+2h — penalty accrual begins ─────────────────────────────
        $report = $overdue->sweep($this->deadline->addHours(2));
        $this->assertSame(1, $report['penalised']);

        $fresh = $settlement->fresh();
        $this->assertSame(OverdueService::LEVEL_PENALTY, $fresh->escalation_level);
        $this->assertSame(self::EXPECTED_PENALTY, $fresh->penalty_rial);
        $this->assertNotNull($fresh->penalty_accrued_at);

        // ── 23:00, T+6h — operator alert and new-order suspension ────────────
        $report = $overdue->sweep($this->deadline->addHours(6));
        $this->assertSame(1, $report['escalated']);

        $fresh = $settlement->fresh();
        $this->assertSame(OverdueService::LEVEL_OPERATOR, $fresh->escalation_level);
        $this->assertSame(SettlementStatus::OVERDUE, $fresh->status, 'Still recoverable at T+6h');

        Event::assertDispatched(
            SettlementOverdue::class,
            static fn (SettlementOverdue $e): bool => $e->escalationLevel === OverdueService::LEVEL_OPERATOR
                && $e->suspendNewOrders === true
                && $e->penaltyRial === self::EXPECTED_PENALTY,
        );

        // ── next day 17:00, T+24h — DEFAULTED ────────────────────────────────
        $report = $overdue->sweep($this->deadline->addHours(24));
        $this->assertSame(1, $report['defaulted']);

        $fresh = $settlement->fresh();
        $this->assertSame(SettlementStatus::DEFAULTED, $fresh->status);
        $this->assertSame(OverdueService::LEVEL_DEFAULTED, $fresh->escalation_level);
        $this->assertSame(self::EXPECTED_PENALTY, $fresh->penalty_rial, 'One day late — 9,810,000 rial');

        Event::assertDispatched(
            SettlementDefaulted::class,
            static fn (SettlementDefaulted $e): bool => $e->defaultingOrgId === self::BUYER
                && $e->injuredOrgId === self::SELLER
                && $e->penaltyRial === self::EXPECTED_PENALTY
                && $e->hoursOverdue === 24,
        );

        // A defaulted settlement is no longer swept.
        $this->assertSame(0, $overdue->sweep($this->deadline->addHours(30))['checked']);
    }

    #[Test]
    #[Group('ledger-invariants')]
    public function the_ladder_moves_nothing_in_the_ledger(): void
    {
        $settlement = $this->openOverdueSettlement();
        $overdue = $this->app->make(OverdueService::class);

        $goldLocked = $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT);
        $cashLocked = $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT);

        foreach ([0, 2, 6, 24] as $hours) {
            $overdue->sweep($this->deadline->addHours($hours));
        }

        // Being late does not release, transfer or seize anything: §5.5 records
        // a penalty and raises alerts, and collateral seizure needs the dual
        // approval of §5.8. The value stays exactly where the lock put it.
        $this->assertSame($goldLocked, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame($cashLocked, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));
        $this->assertSame(
            self::EXPECTED_PENALTY,
            $settlement->fresh()->penalty_rial,
            'The penalty is recorded, not yet collected',
        );

        $this->assertLedgerConserved();
        $this->assertEveryGroupBalances();
    }

    /**
     * Scenario الف of example 6: the buyer pays the next evening. A defaulted
     * settlement can still be recovered — DEFAULTED → SETTLED exists precisely
     * for this, and §2.4 routes a late payer back through PAYMENT_DECLARED.
     */
    #[Test]
    public function a_late_payer_can_still_settle_after_the_deadline(): void
    {
        $settlement = $this->openOverdueSettlement();
        $overdue = $this->app->make(OverdueService::class);

        $overdue->sweep($this->deadline->addHours(6));
        $this->assertSame(SettlementStatus::OVERDUE, $settlement->fresh()->status);

        // OVERDUE → PAYMENT_DECLARED, straight from the state machine.
        $this->app->make(PaymentService::class)->declarePayment(
            settlementId: (int) $settlement->id,
            actorUserId: 7_001,
            paymentReference: 'LATE-987654321',
        );

        $this->assertSame(SettlementStatus::PAYMENT_DECLARED, $settlement->fresh()->status);

        $this->app->make(PaymentService::class)->confirmPayment((int) $settlement->id, 7_002);

        $fresh = $settlement->fresh();
        $this->assertSame(SettlementStatus::GOLD_TRANSFERRING, $fresh->status);
        $this->assertSame(0, $this->rialBalance(self::BUYER, Bucket::IN_SETTLEMENT));
        $this->assertSame(
            self::EXPECTED_PENALTY,
            $fresh->penalty_rial,
            'The recorded penalty survives a late settlement',
        );
    }

    #[Test]
    public function days_overdue_rounds_up_so_any_part_day_of_delay_counts(): void
    {
        $settlement = $this->openOverdueSettlement();
        $overdue = $this->app->make(OverdueService::class);

        $this->assertSame(0, $overdue->daysOverdue($settlement, $this->deadline));
        $this->assertSame(1, $overdue->daysOverdue($settlement, $this->deadline->addHours(1)));
        $this->assertSame(1, $overdue->daysOverdue($settlement, $this->deadline->addHours(24)));
        $this->assertSame(2, $overdue->daysOverdue($settlement, $this->deadline->addHours(25)));
        $this->assertSame(3, $overdue->daysOverdue($settlement, $this->deadline->addDays(3)));

        // F21's own worked vector, three days late on the formulas document's
        // amount: 29,282,850 rial.
        $this->assertSame(
            29_282_850,
            $this->app->make(PenaltyCalculatorInterface::class)->penalty(19_521_900_000, 3, 50, 10_000),
        );
    }

    private function openOverdueSettlement(): SettlementModel
    {
        CarbonImmutable::setTestNow($this->tradedAt);

        $settlement = $this->openSettlement(
            tradeId: 88_231,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: self::FINE_MG,
            grossRial: self::GROSS_RIAL,
            deadlineAt: $this->deadline,
        );

        CarbonImmutable::setTestNow();

        return $settlement;
    }
}
