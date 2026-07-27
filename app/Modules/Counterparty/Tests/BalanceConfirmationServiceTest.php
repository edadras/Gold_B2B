<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\BalanceConfirmationService;
use App\Modules\Counterparty\Domain\ConfirmationStatus;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/** §10.4 — confirm, dispute, and show the two sides where they diverged. */
final class BalanceConfirmationServiceTest extends CounterpartyTestCase
{
    private BalanceConfirmationService $confirmations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmations = $this->app->make(BalanceConfirmationService::class);
    }

    #[Test]
    public function a_request_states_the_balance_as_of_the_date_not_today(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0, occurredAt: Carbon::parse('2026-01-10 09:00:00'));
        $this->relations->applyTrade(101, 202, 90_000, 0, occurredAt: Carbon::parse('2026-02-15 09:00:00'));

        $confirmation = $this->confirmations->request(101, 202, '2026-01-31 23:59:59');

        self::assertSame(250_000, $confirmation->requester_gold_mg);
        self::assertSame(ConfirmationStatus::PENDING, $confirmation->status);
        self::assertSame(340_000, $this->goldBalance(101, 202), 'the live balance is unchanged and different');
    }

    #[Test]
    public function agreeing_closes_the_confirmation_with_a_zero_delta(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0, occurredAt: Carbon::parse('2026-01-10 09:00:00'));

        $confirmation = $this->confirmations->request(101, 202, '2026-01-31');
        $this->confirmations->agree($confirmation->id, 202, respondedByUserId: 77);

        $discrepancy = $this->confirmations->discrepancy($confirmation->id);

        self::assertSame(ConfirmationStatus::AGREED->value, $discrepancy->status);
        self::assertSame(0, $discrepancy->goldDeltaMg);
        self::assertSame(0, $discrepancy->rialDelta);
        self::assertTrue($discrepancy->isResolved());
        self::assertFalse($discrepancy->awaitingResponse());
    }

    #[Test]
    public function a_responder_who_states_the_mirror_figure_is_agreeing(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, -1_000, occurredAt: Carbon::parse('2026-01-10 09:00:00'));

        $confirmation = $this->confirmations->request(101, 202, '2026-01-31');

        // 202 says "I owe 101 250 g and 101 owes me 1,000 rial" — its own
        // convention, the exact mirror of what 101 stated.
        $confirmation = $this->confirmations->dispute($confirmation->id, 202, -250_000, 1_000);

        self::assertSame(ConfirmationStatus::AGREED, $confirmation->status);
        self::assertSame(0, $this->confirmations->discrepancy($confirmation->id)->goldDeltaMg);
    }

    #[Test]
    public function a_real_disagreement_exposes_the_delta_and_both_movement_lists(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0,
            reference: 'TRD-1', occurredAt: Carbon::parse('2026-01-10 09:00:00'));
        $this->relations->applyTrade(101, 202, 60_000, 0,
            reference: 'TRD-2', occurredAt: Carbon::parse('2026-01-20 09:00:00'));

        $confirmation = $this->confirmations->request(101, 202, '2026-01-31', periodStart: '2026-01-01');

        // 202 never booked TRD-2, so it thinks it owes only 250 g.
        $this->confirmations->dispute($confirmation->id, 202, -250_000, 0, note: 'ما ۲۵۰ گرم ثبت کرده‌ایم');

        $discrepancy = $this->confirmations->discrepancy($confirmation->id);

        self::assertSame(ConfirmationStatus::DISPUTED->value, $discrepancy->status);
        self::assertSame(60_000, $discrepancy->goldDeltaMg);
        self::assertFalse($discrepancy->isResolved());

        // Both lists are handed back in the same sign convention, so the missing
        // row is found by scanning amounts rather than by mental arithmetic.
        self::assertCount(2, $discrepancy->requesterMovements);
        self::assertCount(2, $discrepancy->counterpartyMovements);
        self::assertSame(250_000, $discrepancy->counterpartyMovements[0]->goldDeltaMg);
        self::assertSame('TRD-2', $discrepancy->requesterMovements[1]->reference);
    }

    #[Test]
    public function only_the_addressed_counterparty_may_answer(): void
    {
        $confirmation = $this->confirmations->request(101, 202, '2026-01-31');

        $this->expectException(OperationNotPermittedException::class);

        $this->confirmations->agree($confirmation->id, 303);
    }

    #[Test]
    public function an_answered_confirmation_cannot_be_answered_again(): void
    {
        $confirmation = $this->confirmations->request(101, 202, '2026-01-31');
        $this->confirmations->agree($confirmation->id, 202);

        $this->expectException(InvalidStateTransitionException::class);

        $this->confirmations->agree($confirmation->id, 202);
    }

    #[Test]
    public function an_unresolved_discrepancy_can_be_escalated_by_either_side(): void
    {
        $this->relations->applyTrade(101, 202, 250_000, 0, occurredAt: Carbon::parse('2026-01-10 09:00:00'));

        $confirmation = $this->confirmations->request(101, 202, '2026-01-31');
        $this->confirmations->dispute($confirmation->id, 202, -100_000, 0);

        $escalated = $this->confirmations->escalate($confirmation->id, 101);

        self::assertSame(ConfirmationStatus::ESCALATED, $escalated->status);
        self::assertFalse($escalated->status->isOpen());
    }

    #[Test]
    public function the_inbox_shows_only_pending_requests_addressed_to_the_member(): void
    {
        $first = $this->confirmations->request(101, 202, '2026-01-31');
        $this->confirmations->request(103, 202, '2026-01-31');
        $answered = $this->confirmations->request(104, 202, '2026-01-31');
        $this->confirmations->agree($answered->id, 202);
        $this->confirmations->request(202, 101, '2026-01-31');

        $inbox = $this->confirmations->inbox(202);

        self::assertCount(2, $inbox);
        self::assertSame($first->id, $inbox[0]->id);
    }

    #[Test]
    public function nobody_can_confirm_a_balance_with_themselves(): void
    {
        $this->expectException(OperationNotPermittedException::class);

        $this->confirmations->request(101, 101, '2026-01-31');
    }
}
