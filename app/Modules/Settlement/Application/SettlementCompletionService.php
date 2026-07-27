<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\SettlementCompleted;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Carbon\CarbonImmutable;

/**
 * SETTLED → COMPLETED once the objection window has closed — the last row of
 * the §5.2 happy path, and the "پنجره اعتراض ۲۴ ساعته" of §5.3 pattern 3.
 *
 * The window exists because a physical delivery can only be checked after the
 * fact: the buyer needs time to weigh and assay what they received before the
 * settlement becomes final. Nothing moves in the ledger at COMPLETED — the
 * value changed hands at SETTLED. What changes is that the settlement stops
 * counting as open, which is what Identity's CLOSED precondition and the
 * member's dashboard care about.
 *
 * COMPLETED is not beyond correction: appendix §2.14 rule 3 keeps DISPUTED and
 * REVERSED reachable, because fraud discovered a week later must still be
 * fixable.
 */
final readonly class SettlementCompletionService
{
    public function __construct(private SettlementStateMachine $stateMachine) {}

    /**
     * Complete one settlement.
     *
     * @param  bool  $force  operator override, e.g. both parties waive the window
     * @param  ?CarbonImmutable  $now  evaluate the window as at this moment; the sweep
     *                                 passes its own clock so a replay stays deterministic
     */
    public function complete(
        int $settlementId,
        ?int $actorUserId = null,
        bool $force = false,
        ?CarbonImmutable $now = null,
    ): SettlementModel {
        $settlement = $this->stateMachine->lock($settlementId);

        if ($settlement->status !== SettlementStatus::SETTLED) {
            throw new OperationNotPermittedException(
                'Only a settled settlement can be completed; it is '.$settlement->status->value
            );
        }

        if (! $force && ! $this->windowHasClosed($settlement, $now)) {
            throw new OperationNotPermittedException(sprintf(
                'The %d-hour objection window has not closed yet',
                $this->windowHours(),
            ));
        }

        $this->stateMachine->transition(
            $settlementId,
            SettlementStatus::COMPLETED,
            new TransitionContext(
                actorUserId: $actorUserId,
                reason: $force ? 'Objection window waived' : 'Objection window closed',
                metadata: ['window_hours' => $this->windowHours(), 'forced' => $force],
            ),
        );

        $settlement->refresh();

        event(new SettlementCompleted(
            settlementId: (int) $settlement->id,
            settlementCode: (string) $settlement->settlement_code,
            tradeId: $settlement->trade_id,
            goldDelivererOrgId: $settlement->gold_deliverer_org_id,
            goldReceiverOrgId: $settlement->gold_receiver_org_id,
            fineWeightMg: $settlement->fine_weight_mg,
            cashAmountRial: $settlement->cash_amount_rial,
            settledAt: (string) $settlement->settled_at?->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $settlement;
    }

    /**
     * Sweep every settlement whose window has closed. Run from the scheduler
     * alongside settlement:check-overdue.
     *
     * @return list<int> the settlement ids completed
     */
    public function completeDue(?CarbonImmutable $now = null, int $limit = 500): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subHours($this->windowHours());

        $ids = SettlementModel::query()
            ->where('status', SettlementStatus::SETTLED->value)
            ->whereNotNull('settled_at')
            ->where('settled_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $completed = [];

        foreach ($ids as $id) {
            $this->complete((int) $id, null, false, $now ?? CarbonImmutable::now());
            $completed[] = (int) $id;
        }

        return $completed;
    }

    public function windowHours(): int
    {
        return (int) config('goldb2b.settlement.completion_window_hours', 24);
    }

    public function windowHasClosed(SettlementModel $settlement, ?CarbonImmutable $now = null): bool
    {
        if ($settlement->settled_at === null) {
            return false;
        }

        $deadline = CarbonImmutable::parse($settlement->settled_at)->addHours($this->windowHours());

        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($deadline);
    }
}
