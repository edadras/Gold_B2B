<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * `php artisan settlement:check-overdue` — the scheduled clock behind §5.5.
 *
 * The --at option exists so the ladder can be driven deterministically here and
 * replayed by an operator investigating what a past run did.
 *
 * The command exits non-zero when anything defaulted: a default is a credit
 * event that has to reach a human, not routine housekeeping the scheduler
 * silently swallows.
 */
final class CheckOverdueCommandTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    private CarbonImmutable $deadline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deadline = CarbonImmutable::parse('2026-07-27T17:00:00Z');

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 1_000_000);
        $this->depositRial(self::BUYER, 25_000_000_000);
    }

    #[Test]
    public function the_command_reports_a_clean_sweep_and_exits_zero(): void
    {
        $this->overdueSettlement();

        $this->artisan('settlement:check-overdue', ['--at' => $this->deadline->subHour()->toIso8601String()])
            ->expectsOutputToContain('Settlements examined: 0')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_command_marks_the_settlement_overdue_and_still_exits_zero(): void
    {
        $settlement = $this->overdueSettlement();

        $this->artisan('settlement:check-overdue', ['--at' => $this->deadline->addHours(6)->toIso8601String()])
            ->expectsOutputToContain('Settlements examined: 1')
            ->assertExitCode(0);

        $this->assertSame(SettlementStatus::OVERDUE, $settlement->fresh()->status);
        $this->assertSame(9_810_000, $settlement->fresh()->penalty_rial);
    }

    #[Test]
    public function a_default_makes_the_command_exit_non_zero(): void
    {
        $settlement = $this->overdueSettlement();

        $this->artisan('settlement:check-overdue', ['--at' => $this->deadline->addHours(24)->toIso8601String()])
            ->assertExitCode(1);

        $this->assertSame(SettlementStatus::DEFAULTED, $settlement->fresh()->status);
    }

    #[Test]
    public function the_complete_flag_also_closes_finished_settlements(): void
    {
        $this->overdueSettlement();

        $this->artisan('settlement:check-overdue', [
            '--at' => $this->deadline->addHours(2)->toIso8601String(),
            '--complete' => true,
        ])
            ->expectsOutputToContain('Objection window closed on 0 settlement(s)')
            ->assertExitCode(0);
    }

    private function overdueSettlement(): SettlementModel
    {
        CarbonImmutable::setTestNow($this->deadline->subHours(8));

        $settlement = $this->openSettlement(
            tradeId: 96_001,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: 250_000,
            grossRial: 19_620_000_000,
            deadlineAt: $this->deadline,
        );

        CarbonImmutable::setTestNow();

        return $settlement;
    }
}
