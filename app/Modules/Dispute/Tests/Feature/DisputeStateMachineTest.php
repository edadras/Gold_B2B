<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Feature;

use App\Modules\Dispute\Application\DisputeStateMachine;
use App\Modules\Dispute\Application\TransitionContext;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Dispute\Tests\DisputeTestCase;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §2.14 rule 7 — «برای هر ماشین حالت، تست جامع تمام گذارهای مجاز و رد تمام
 * گذارهای غیرمجاز».
 *
 * Every ordered pair of states is exercised: the legal ones must succeed, and
 * every one of the remaining ninety-odd must be refused.
 */
final class DisputeStateMachineTest extends DisputeTestCase
{
    private DisputeStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateMachine = $this->app->make(DisputeStateMachine::class);
    }

    #[Test]
    public function every_transition_is_accepted_or_rejected_exactly_as_specified(): void
    {
        $legal = 0;
        $illegal = 0;

        foreach (DisputeStatus::cases() as $from) {
            foreach (DisputeStatus::cases() as $to) {
                $dispute = $this->disputeIn($from);

                if ($from->canTransitionTo($to)) {
                    $this->stateMachine->transition(
                        $dispute,
                        $to,
                        TransitionContext::system('TEST'),
                    );

                    self::assertSame(
                        $to->value,
                        (string) DisputeModel::query()->whereKey($dispute->id)->value('status'),
                        "{$from->value} → {$to->value} should have been allowed",
                    );

                    $legal++;

                    continue;
                }

                try {
                    $this->stateMachine->transition($dispute, $to, TransitionContext::system('TEST'));
                    self::fail("{$from->value} → {$to->value} should have been refused");
                } catch (InvalidStateTransitionException $e) {
                    self::assertSame('Dispute', $e->entity);
                    self::assertSame($from->value, $e->from);
                    self::assertSame($to->value, $e->to);
                }

                self::assertSame(
                    $from->value,
                    (string) DisputeModel::query()->whereKey($dispute->id)->value('status'),
                    'a refused transition must leave the status alone',
                );

                $illegal++;
            }
        }

        // 10 states × 10 targets = 100 pairs; the appendix permits 16 of them.
        self::assertSame(16, $legal, 'the number of legal transitions in §2.8');
        self::assertSame(84, $illegal);
    }

    #[Test]
    public function the_documented_edges_are_present(): void
    {
        // Read straight off the §2.8 diagram.
        $expected = [
            'OPENED' => ['AWAITING_REPLY', 'WITHDRAWN'],
            'AWAITING_REPLY' => ['ACCEPTED_BY_RESPONDENT', 'NEGOTIATION', 'UNDER_MEDIATION', 'WITHDRAWN'],
            'ACCEPTED_BY_RESPONDENT' => ['RESOLVED'],
            'NEGOTIATION' => ['RESOLVED', 'UNDER_MEDIATION', 'WITHDRAWN'],
            'UNDER_MEDIATION' => ['AWAITING_EVIDENCE', 'AWAITING_REASSAY', 'RESOLVED'],
            'AWAITING_EVIDENCE' => ['UNDER_MEDIATION'],
            'AWAITING_REASSAY' => ['UNDER_MEDIATION'],
            'RESOLVED' => ['EXECUTED'],
            'EXECUTED' => [],
            'WITHDRAWN' => [],
        ];

        $actual = [];

        foreach (DisputeStatus::cases() as $status) {
            $actual[$status->value] = array_map(
                static fn (DisputeStatus $s): string => $s->value,
                $status->allowedTransitions(),
            );
        }

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function only_withdrawn_and_executed_are_final(): void
    {
        $final = array_values(array_map(
            static fn (DisputeStatus $s): string => $s->value,
            array_filter(DisputeStatus::cases(), static fn (DisputeStatus $s): bool => $s->isFinal()),
        ));

        self::assertSame(['EXECUTED', 'WITHDRAWN'], $final);
    }

    #[Test]
    public function every_transition_is_written_to_the_timeline(): void
    {
        $dispute = $this->disputeIn(DisputeStatus::OPENED);

        $this->stateMachine->transition(
            $dispute,
            DisputeStatus::AWAITING_REPLY,
            TransitionContext::system('AUTO_AWAIT_REPLY', 'مهلت پاسخ آغاز شد'),
        );

        $row = DB::table('dispute_timeline')
            ->where('dispute_id', $dispute->id)
            ->orderByDesc('id')
            ->first();

        self::assertNotNull($row);
        self::assertSame('SYSTEM', $row->actor_type);
        self::assertSame('AUTO_AWAIT_REPLY', $row->action);
        self::assertSame('OPENED', $row->from_status);
        self::assertSame('AWAITING_REPLY', $row->to_status);
    }

    #[Test]
    public function entering_a_timed_state_starts_its_clock_and_leaving_it_stops_it(): void
    {
        $dispute = $this->disputeIn(DisputeStatus::OPENED);

        $this->stateMachine->transition($dispute, DisputeStatus::AWAITING_REPLY, TransitionContext::system('X'));
        self::assertNotNull($dispute->reply_deadline_at);
        self::assertNull($dispute->negotiation_deadline_at);

        $this->stateMachine->transition($dispute, DisputeStatus::NEGOTIATION, TransitionContext::system('X'));
        self::assertNull($dispute->reply_deadline_at, 'the reply clock stops when the reply arrives');
        self::assertNotNull($dispute->negotiation_deadline_at);

        $this->stateMachine->transition($dispute, DisputeStatus::UNDER_MEDIATION, TransitionContext::system('X'));
        self::assertNull($dispute->negotiation_deadline_at, 'mediation is not on a clock');
    }

    #[Test]
    public function only_the_two_negotiation_states_carry_deadlines(): void
    {
        foreach (DisputeStatus::cases() as $status) {
            $expected = in_array($status, [DisputeStatus::AWAITING_REPLY, DisputeStatus::NEGOTIATION], true);

            self::assertSame($expected, $status->hasDeadline(), $status->value);

            self::assertSame(
                $expected ? DisputeStatus::UNDER_MEDIATION : null,
                $status->onDeadlineExpiry(),
                $status->value,
            );
        }
    }

    private function disputeIn(DisputeStatus $status): DisputeModel
    {
        static $sequence = 0;
        $sequence++;

        /** @var DisputeModel $dispute */
        $dispute = DisputeModel::query()->create([
            'case_number' => sprintf('DSP-1404-%05d', $sequence),
            'dispute_type' => DisputeType::AMOUNT_MISMATCH->value,
            'claimant_org_id' => 184,
            'respondent_org_id' => 209,
            'opened_by_user_id' => 41,
            'claim_description' => 'fixture',
            'claim_gold_mg' => 0,
            'claim_rial' => 0,
            'status' => $status->value,
            'priority' => 'NORMAL',
            'opened_at' => Carbon::now(),
        ]);

        return $dispute;
    }
}
