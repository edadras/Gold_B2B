<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LotStatusEventModel;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way to change gold_lots.status.
 *
 * Follows the pattern in docs/11-appendix/02-state-machines.md §2.1: validate
 * against the enum's transition table, persist, then append an immutable row to
 * lot_status_events. Runs inside the caller's transaction when there is one
 * (nested calls become savepoints).
 */
final class LotStateMachine
{
    public function transition(
        GoldLotModel $lot,
        LotStatus $target,
        TransitionContext $context,
    ): GoldLotModel {
        $current = $lot->status;

        if (! $current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                entity: 'GoldLot',
                from: $current->value,
                to: $target->value,
            );
        }

        return DB::transaction(function () use ($lot, $current, $target, $context): GoldLotModel {
            $lot->status = $target;
            $lot->version = (int) $lot->version + 1;

            if ($target === LotStatus::ON_HOLD) {
                $lot->hold_reason = $context->reason;
            } elseif ($current === LotStatus::ON_HOLD) {
                $lot->hold_reason = null;
            }

            $lot->save();

            LotStatusEventModel::query()->create([
                'gold_lot_id' => $lot->id,
                'from_status' => $current->value,
                'to_status' => $target->value,
                'actor_type' => $context->actorType,
                'actor_user_id' => $context->actorUserId,
                'reason' => $context->reason,
                'reference_type' => $context->referenceType,
                'reference_id' => $context->referenceId,
                'metadata' => $context->metadata === [] ? null : $context->metadata,
                'created_at' => now(),
            ]);

            return $lot;
        });
    }

    /** Throws unless the lot may move to $target right now. */
    public function assertCanTransition(GoldLotModel $lot, LotStatus $target): void
    {
        if (! $lot->status->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                entity: 'GoldLot',
                from: $lot->status->value,
                to: $target->value,
            );
        }
    }

    public function canTransition(GoldLotModel $lot, LotStatus $target): bool
    {
        return $lot->status->canTransitionTo($target);
    }
}
