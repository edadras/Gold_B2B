<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Feature;

use App\Modules\Settlement\Application\SettlementStateMachine;
use App\Modules\Settlement\Domain\ActorType;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Infrastructure\Models\SettlementEventModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Settlement\Tests\Support\SettlementTestCase;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The exhaustive machine test appendix §2.14 rule 7 asks for, in the shape the
 * document's own sample sketches: every ordered pair of the fourteen states is
 * attempted, the legal ones must succeed and the illegal ones must throw.
 *
 * That is 14 × 14 = 196 attempts, of which 35 are legal.
 *
 * Ledger side effects are switched off for the sweep. What is under test here
 * is the guard and the audit row — whether a transition is permitted at all and
 * whether it is recorded — and giving 196 throwaway settlements real reserved
 * balances would test the ledger instead. The side effects themselves are
 * covered against the documents' own figures in
 * WorkedExampleOneSettlementTest, WorkedExampleThreeNettingTest and
 * ReversalServiceTest; the two tests at the bottom of this file check that they
 * do fire when they are left on.
 */
final class SettlementStateMachineTest extends SettlementTestCase
{
    private const SELLER = 184;

    private const BUYER = 291;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLedger(self::SELLER, self::BUYER);
        $this->depositGold(self::SELLER, 10_000_000);
        $this->depositRial(self::BUYER, 100_000_000_000);
    }

    #[Test]
    public function every_legal_transition_succeeds_and_every_illegal_one_throws(): void
    {
        $machine = $this->app->make(SettlementStateMachine::class);
        $legal = 0;
        $illegal = 0;

        foreach (SettlementStatus::cases() as $from) {
            foreach (SettlementStatus::cases() as $to) {
                $settlement = $this->settlementIn($from);
                $context = new TransitionContext(
                    actorType: ActorType::SYSTEM,
                    reason: 'exhaustive machine test',
                    applyLedgerEffects: false,
                );

                if ($from->canTransitionTo($to)) {
                    $machine->transition((int) $settlement->id, $to, $context);

                    $this->assertSame(
                        $to,
                        $settlement->fresh()->status,
                        sprintf('%s → %s should be allowed', $from->value, $to->value),
                    );
                    $legal++;

                    continue;
                }

                try {
                    $machine->transition((int) $settlement->id, $to, $context);
                    $this->fail(sprintf('%s → %s should have been refused', $from->value, $to->value));
                } catch (InvalidStateTransitionException $e) {
                    $this->assertSame('Settlement', $e->entity);
                    $this->assertSame($from->value, $e->from);
                    $this->assertSame($to->value, $e->to);
                    $this->assertSame(
                        $from,
                        $settlement->fresh()->status,
                        'A refused transition must leave the status untouched',
                    );
                    $illegal++;
                }
            }
        }

        $this->assertSame(196, $legal + $illegal, 'Every ordered pair of the 14 states was attempted');
        $this->assertSame(35, $legal, 'The §2.4 diagram defines 35 legal transitions');
    }

    #[Test]
    public function a_refused_transition_writes_no_event_row(): void
    {
        $machine = $this->app->make(SettlementStateMachine::class);
        $settlement = $this->settlementIn(SettlementStatus::COMPLETED);

        $before = SettlementEventModel::query()->where('settlement_id', $settlement->id)->count();

        try {
            $machine->transition(
                (int) $settlement->id,
                SettlementStatus::PAYMENT_PENDING,
                TransitionContext::system('nope'),
            );
            $this->fail('COMPLETED → PAYMENT_PENDING must be refused');
        } catch (InvalidStateTransitionException) {
            // expected
        }

        $this->assertSame(
            $before,
            SettlementEventModel::query()->where('settlement_id', $settlement->id)->count(),
        );
    }

    #[Test]
    public function every_transition_records_who_when_and_why(): void
    {
        $machine = $this->app->make(SettlementStateMachine::class);
        $settlement = $this->settlementIn(SettlementStatus::PAYMENT_PENDING);

        $machine->transition(
            (int) $settlement->id,
            SettlementStatus::PAYMENT_DECLARED,
            TransitionContext::user(4_242, 'Payer says they paid', ['reference' => '987654321']),
        );

        $event = SettlementEventModel::query()
            ->where('settlement_id', $settlement->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(SettlementStatus::PAYMENT_PENDING->value, $event->from_status);
        $this->assertSame(SettlementStatus::PAYMENT_DECLARED->value, $event->to_status);
        $this->assertSame(ActorType::USER, $event->actor_type);
        $this->assertSame(4_242, (int) $event->actor_user_id);
        $this->assertSame('Payer says they paid', $event->reason);
        $this->assertSame(['reference' => '987654321'], $event->metadata);
        $this->assertNotNull($event->occurred_at);
    }

    #[Test]
    public function an_automatic_transition_is_recorded_as_system(): void
    {
        $machine = $this->app->make(SettlementStateMachine::class);
        $settlement = $this->settlementIn(SettlementStatus::ASSETS_LOCKED);

        $machine->transition(
            (int) $settlement->id,
            SettlementStatus::PAYMENT_PENDING,
            TransitionContext::system('automatic'),
        );

        $event = SettlementEventModel::query()
            ->where('settlement_id', $settlement->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(ActorType::SYSTEM, $event->actor_type);
        $this->assertNull($event->actor_user_id);
    }

    #[Test]
    public function the_terminal_states_have_no_way_out(): void
    {
        $this->assertTrue(SettlementStatus::CANCELLED->isFinal());
        $this->assertTrue(SettlementStatus::REVERSED->isFinal());

        foreach (SettlementStatus::cases() as $status) {
            if ($status === SettlementStatus::CANCELLED || $status === SettlementStatus::REVERSED) {
                continue;
            }

            $this->assertFalse($status->isFinal(), $status->value.' should not be terminal');
        }

        // COMPLETED is financially final but still correctable — §2.14 rule 3.
        $this->assertFalse(SettlementStatus::COMPLETED->isFinal());
        $this->assertFalse(SettlementStatus::COMPLETED->isOpen());
        $this->assertTrue(SettlementStatus::COMPLETED->canTransitionTo(SettlementStatus::REVERSED));
        $this->assertTrue(SettlementStatus::COMPLETED->canTransitionTo(SettlementStatus::DISPUTED));
    }

    #[Test]
    public function cancelling_releases_exactly_what_was_locked(): void
    {
        $settlement = $this->openSettlement(
            tradeId: 5_001,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: 100_000,
            grossRial: 7_800_000_000,
            buyerFee: 11_700_000,
        );

        $goldAvailable = $this->goldBalance(self::SELLER);
        $rialAvailable = $this->rialBalance(self::BUYER);

        $this->app->make(SettlementStateMachine::class)->transition(
            (int) $settlement->id,
            SettlementStatus::CANCELLED,
            TransitionContext::staff(9_001, 'Both parties agreed to call it off'),
        );

        $fresh = $settlement->fresh();
        $this->assertSame(SettlementStatus::CANCELLED, $fresh->status);
        $this->assertSame(0, $fresh->locked_gold_mg);
        $this->assertSame(0, $fresh->locked_cash_rial);
        $this->assertNull($fresh->held_bucket);

        $this->assertSame($goldAvailable + 100_000, $this->goldBalance(self::SELLER));
        $this->assertSame($rialAvailable + 7_811_700_000, $this->rialBalance(self::BUYER));
        $this->assertLedgerConserved();
    }

    #[Test]
    public function disputing_quarantines_the_holdings_and_settling_releases_them(): void
    {
        $settlement = $this->openSettlement(
            tradeId: 5_002,
            sellerOrgId: self::SELLER,
            buyerOrgId: self::BUYER,
            fineMg: 100_000,
            grossRial: 7_800_000_000,
        );

        $machine = $this->app->make(SettlementStateMachine::class);

        $machine->transition(
            (int) $settlement->id,
            SettlementStatus::DISPUTED,
            TransitionContext::user(7_001, 'Purity contested'),
        );

        $fresh = $settlement->fresh();
        $this->assertSame(\App\Modules\Ledger\Domain\Bucket::IN_DISPUTE->value, $fresh->held_bucket);
        $this->assertSame(100_000, $this->goldBalance(self::SELLER, \App\Modules\Ledger\Domain\Bucket::IN_DISPUTE));
        $this->assertSame(0, $this->goldBalance(self::SELLER, \App\Modules\Ledger\Domain\Bucket::IN_SETTLEMENT));

        // Dispute resolved in the buyer's favour: the settlement goes through.
        $machine->transition(
            (int) $settlement->id,
            SettlementStatus::SETTLED,
            TransitionContext::staff(9_002, 'Dispute resolved — settlement stands'),
        );

        $this->assertSame(0, $this->goldBalance(self::SELLER, \App\Modules\Ledger\Domain\Bucket::IN_DISPUTE));
        $this->assertSame(100_000, $this->goldBalance(self::BUYER));
        $this->assertLedgerConserved();
    }

    /**
     * A settlement parked in an arbitrary status, with no holdings, so a
     * transition's bookkeeping runs but its ledger side effect has nothing to
     * do even when it is left enabled.
     */
    private function settlementIn(SettlementStatus $status): SettlementModel
    {
        static $sequence = 0;
        $sequence++;

        $settlement = SettlementModel::query()->create([
            'settlement_code' => sprintf('STL-T%07d', $sequence),
            'trade_id' => 900_000 + $sequence,
            'settlement_type' => 'T0',
            'gold_deliverer_org_id' => self::SELLER,
            'gold_receiver_org_id' => self::BUYER,
            'cash_payer_org_id' => self::BUYER,
            'cash_receiver_org_id' => self::SELLER,
            'fine_weight_mg' => 1_000,
            'cash_amount_rial' => 78_480_000,
            'buyer_fee_rial' => 117_720,
            'seller_fee_rial' => 78_480,
            'delivery_method' => 'CUSTODY_CHANGE',
            'payment_method' => 'BANK_TRANSFER',
            'deadline_at' => now()->addHours(8),
            'status' => $status->value,
        ]);

        return $settlement->refresh();
    }
}
