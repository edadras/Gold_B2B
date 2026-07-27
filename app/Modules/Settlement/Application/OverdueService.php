<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Risk\Contracts\PenaltyCalculatorInterface;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\SettlementDefaulted;
use App\Modules\Settlement\Events\SettlementOverdue;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Support\IntMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The escalation ladder of docs/03-domain/05-settlement.md §5.5, and worked
 * example 6.
 *
 *   T+0h   status → OVERDUE, both sides notified, the debtor's risk profile
 *          records it                                        (level 1)
 *   T+2h   second reminder, and late-payment penalty starts
 *          accruing (F21)                                    (level 2)
 *   T+6h   operator alerted, new order entry suspended for
 *          the defaulting member                             (level 3)
 *   T+24h  status → DEFAULTED: credit score −250, risk level
 *          HIGH, collateral considered, dispute opened       (level 4)
 *   T+72h  full membership suspension, union notified
 *
 * The last rung belongs to Identity and Reputation; Settlement raises the
 * event and they act. Everything below it is this class.
 *
 * escalation_level on the settlement row is what makes the ladder idempotent:
 * the sweep runs every ten minutes and each rung fires exactly once, however
 * many times it is called.
 *
 * The penalty itself goes through Risk's PenaltyCalculatorInterface (F21), so
 * the rate and the 10 % cap live in one place — they have to appear in the
 * membership agreement and be approved as شرعی/legally sound, which is a
 * policy decision, not a settlement one.
 */
final readonly class OverdueService
{
    /** §5.5 rung boundaries, in hours past the deadline. */
    public const PENALTY_AFTER_HOURS = 2;

    public const OPERATOR_ALERT_AFTER_HOURS = 6;

    public const DEFAULT_AFTER_HOURS = 24;

    public const LEVEL_NONE = 0;

    public const LEVEL_OVERDUE = 1;

    public const LEVEL_PENALTY = 2;

    public const LEVEL_OPERATOR = 3;

    public const LEVEL_DEFAULTED = 4;

    public function __construct(
        private SettlementStateMachine $stateMachine,
        private PenaltyCalculatorInterface $penalties,
    ) {}

    /**
     * Walk every open settlement past its deadline up the ladder.
     *
     * @return array{checked: int, overdue: int, penalised: int, escalated: int, defaulted: int, settlement_ids: list<int>}
     */
    public function sweep(?CarbonImmutable $now = null, int $limit = 500): array
    {
        $now ??= CarbonImmutable::now();
        $grace = (int) config('goldb2b.settlement.default_grace_minutes', 0);
        $cutoff = $now->subMinutes($grace);

        $ids = SettlementModel::query()
            ->awaitingPayment()
            ->where('deadline_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $report = [
            'checked' => 0,
            'overdue' => 0,
            'penalised' => 0,
            'escalated' => 0,
            'defaulted' => 0,
            'settlement_ids' => [],
        ];

        foreach ($ids as $id) {
            $outcome = $this->escalate((int) $id, $now);

            $report['checked']++;
            $report['settlement_ids'][] = (int) $id;

            foreach (['overdue', 'penalised', 'escalated', 'defaulted'] as $key) {
                if ($outcome[$key]) {
                    $report[$key]++;
                }
            }
        }

        return $report;
    }

    /**
     * Advance one settlement as far up the ladder as the clock allows.
     *
     * @return array{overdue: bool, penalised: bool, escalated: bool, defaulted: bool, level: int, penalty: int, hours: int}
     */
    public function escalate(int $settlementId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        /** @var array{outcome: array{overdue: bool, penalised: bool, escalated: bool, defaulted: bool, level: int, penalty: int, hours: int}, events: list<object>} $result */
        $result = DB::transaction(function () use ($settlementId, $now): array {
            $settlement = $this->stateMachine->lock($settlementId);

            $outcome = [
                'overdue' => false,
                'penalised' => false,
                'escalated' => false,
                'defaulted' => false,
                'level' => $settlement->escalation_level,
                'penalty' => $settlement->penalty_rial,
                'hours' => 0,
            ];

            if (! $settlement->status->isAwaitingPayment() || ! $this->isPastDeadline($settlement, $now)) {
                return ['outcome' => $outcome, 'events' => []];
            }

            // ASSETS_LOCKED has no edge to OVERDUE (§2.4): the automatic
            // ASSETS_LOCKED → PAYMENT_PENDING step simply had not run yet.
            if ($settlement->status === SettlementStatus::ASSETS_LOCKED) {
                $this->stateMachine->transition(
                    $settlementId,
                    SettlementStatus::PAYMENT_PENDING,
                    TransitionContext::system('Payment window opened'),
                );
                $settlement = $this->stateMachine->lock($settlementId);
            }

            // T+0 — the deadline passed.
            if ($settlement->status !== SettlementStatus::OVERDUE) {
                $this->stateMachine->transition(
                    $settlementId,
                    SettlementStatus::OVERDUE,
                    TransitionContext::system('Deadline passed'),
                );
                $settlement = $this->stateMachine->lock($settlementId);
                $outcome['overdue'] = true;
            }

            $hours = $this->hoursOverdue($settlement, $now);
            $outcome['hours'] = $hours;
            $events = [];

            if ($settlement->escalation_level < self::LEVEL_OVERDUE) {
                $settlement->escalation_level = self::LEVEL_OVERDUE;
                $outcome['overdue'] = true;
            }

            // T+2h — penalty accrual starts and keeps up with the clock.
            if ($hours >= self::PENALTY_AFTER_HOURS) {
                $penalty = $this->penaltyFor($settlement, $now);

                if ($penalty !== $settlement->penalty_rial) {
                    $settlement->penalty_rial = $penalty;
                    $outcome['penalised'] = true;
                }

                if ($settlement->penalty_accrued_at === null) {
                    $settlement->penalty_accrued_at = $settlement->overdue_since;
                    $outcome['penalised'] = true;
                }

                if ($settlement->escalation_level < self::LEVEL_PENALTY) {
                    $settlement->escalation_level = self::LEVEL_PENALTY;
                    $outcome['penalised'] = true;
                }
            }

            // T+6h — operator alerted, the member may place no new orders.
            if ($hours >= self::OPERATOR_ALERT_AFTER_HOURS
                && $settlement->escalation_level < self::LEVEL_OPERATOR) {
                $settlement->escalation_level = self::LEVEL_OPERATOR;
                $outcome['escalated'] = true;
            }

            $settlement->save();

            $events[] = new SettlementOverdue(
                settlementId: $settlementId,
                settlementCode: (string) $settlement->settlement_code,
                cashPayerOrgId: $settlement->cash_payer_org_id,
                cashReceiverOrgId: $settlement->cash_receiver_org_id,
                amountRial: $settlement->totalCashDue()->amount,
                deadlineAt: (string) $settlement->deadline_at?->toIso8601String(),
                overdueSince: (string) $settlement->overdue_since?->toIso8601String(),
                hoursOverdue: $hours,
                escalationLevel: $settlement->escalation_level,
                penaltyRial: $settlement->penalty_rial,
                suspendNewOrders: $settlement->escalation_level >= self::LEVEL_OPERATOR,
                occurredAt: $now->toIso8601String(),
            );

            // T+24h — default.
            if ($hours >= self::DEFAULT_AFTER_HOURS) {
                $this->stateMachine->transition(
                    $settlementId,
                    SettlementStatus::DEFAULTED,
                    TransitionContext::system(sprintf('No payment %d hours past deadline', $hours)),
                );

                $settlement = $this->stateMachine->lock($settlementId);
                $settlement->escalation_level = self::LEVEL_DEFAULTED;
                $settlement->save();

                $outcome['defaulted'] = true;

                $events[] = new SettlementDefaulted(
                    settlementId: $settlementId,
                    settlementCode: (string) $settlement->settlement_code,
                    defaultingOrgId: $settlement->cash_payer_org_id,
                    injuredOrgId: $settlement->cash_receiver_org_id,
                    amountRial: $settlement->totalCashDue()->amount,
                    fineWeightMg: $settlement->fine_weight_mg,
                    penaltyRial: $settlement->penalty_rial,
                    overdueSince: (string) $settlement->overdue_since?->toIso8601String(),
                    hoursOverdue: $hours,
                    occurredAt: $now->toIso8601String(),
                );
            }

            $outcome['level'] = $settlement->escalation_level;
            $outcome['penalty'] = $settlement->penalty_rial;

            return ['outcome' => $outcome, 'events' => $events];
        }, attempts: 3);

        // After commit, never inside it (AGENT_BRIEF rule 3).
        foreach ($result['events'] as $event) {
            event($event);
        }

        return $result['outcome'];
    }

    /**
     * F21 on the amount the payer owes.
     *
     * Worked example 6: 19,620,000,000 rial, one day late, daily rate 50
     * ×100,000 → 9,810,000 rial, well under the 10 % cap of 1,962,000,000.
     */
    public function penaltyFor(SettlementModel $settlement, ?CarbonImmutable $now = null): int
    {
        return $this->penalties->penalty(
            $settlement->totalCashDue()->amount,
            $this->daysOverdue($settlement, $now),
        );
    }

    /**
     * Days late, rounded up: any part-day of delay counts as a whole one.
     *
     * The document's own example calls a delay that reaches the next day
     * "days_overdue = 1", and the ladder starts charging at T+2h, so the first
     * chargeable day begins the moment the deadline passes. Integer division
     * only — no ceil(), which is banned on a financial path.
     */
    public function daysOverdue(SettlementModel $settlement, ?CarbonImmutable $now = null): int
    {
        $seconds = $this->secondsOverdue($settlement, $now);

        if ($seconds <= 0) {
            return 0;
        }

        $whole = intdiv($seconds, 86_400);

        return $seconds % 86_400 === 0 ? $whole : $whole + 1;
    }

    public function hoursOverdue(SettlementModel $settlement, ?CarbonImmutable $now = null): int
    {
        return intdiv($this->secondsOverdue($settlement, $now), 3_600);
    }

    public function isPastDeadline(SettlementModel $settlement, ?CarbonImmutable $now = null): bool
    {
        $grace = (int) config('goldb2b.settlement.default_grace_minutes', 0);

        return ($now ?? CarbonImmutable::now())
            ->greaterThanOrEqualTo(CarbonImmutable::parse($settlement->deadline_at)->addMinutes($grace));
    }

    /**
     * Seconds past the deadline. Measured from deadline_at, not overdue_since:
     * a sweep that runs late must not shorten the delay it reports.
     */
    private function secondsOverdue(SettlementModel $settlement, ?CarbonImmutable $now = null): int
    {
        $deadline = CarbonImmutable::parse($settlement->deadline_at);
        $moment = $now ?? CarbonImmutable::now();

        return IntMath::sub($moment->getTimestamp(), $deadline->getTimestamp());
    }
}
