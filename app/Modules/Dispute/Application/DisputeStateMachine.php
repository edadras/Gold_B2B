<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Events\DisputeStatusChanged;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Dispute\Infrastructure\Models\DisputeTimelineModel;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way a dispute's status changes.
 *
 * Follows the pattern §2.1 lays out and the rules of §2.14: the transition is
 * validated against allowedTransitions() first, the status change and its
 * timeline row are written in one transaction, deadline side effects are
 * applied inside it, and the event is fired after the commit.
 *
 * Deadlines are set here rather than in the calling service because they are a
 * property of the state, not of the reason for entering it: any route into
 * AWAITING_REPLY starts the 24-hour clock, and any route into NEGOTIATION
 * starts the 48-hour one (§13.6).
 */
final class DisputeStateMachine
{
    public function transition(
        DisputeModel $dispute,
        DisputeStatus $target,
        TransitionContext $context,
    ): DisputeModel {
        $current = $dispute->statusEnum();

        if (! $current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException('Dispute', $current->value, $target->value);
        }

        DB::transaction(function () use ($dispute, $current, $target, $context): void {
            /** @var DisputeModel $locked */
            $locked = DisputeModel::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            // Re-check under the lock: two mediators clicking at once must not
            // both succeed, and the second one's move may no longer be legal.
            $observed = $locked->statusEnum();

            if ($observed !== $current) {
                if (! $observed->canTransitionTo($target)) {
                    throw new InvalidStateTransitionException('Dispute', $observed->value, $target->value);
                }
            }

            $attributes = ['status' => $target->value];
            $attributes += $this->deadlinesFor($target);

            if ($target === DisputeStatus::RESOLVED) {
                $attributes['resolved_at'] = Carbon::now();
            }

            if ($target === DisputeStatus::EXECUTED) {
                $attributes['executed_at'] = Carbon::now();
            }

            if ($target === DisputeStatus::WITHDRAWN) {
                $attributes['resolved_at'] = Carbon::now();
            }

            $locked->update($attributes);

            DisputeTimelineModel::query()->create([
                'dispute_id' => $locked->id,
                'actor_type' => $context->actorType->value,
                'actor_user_id' => $context->actorUserId,
                'actor_org_id' => $context->actorOrgId,
                'action' => $context->action !== '' ? $context->action : 'STATUS_CHANGED',
                'message' => $context->message,
                'from_status' => $observed->value,
                'to_status' => $target->value,
                'occurred_at' => Carbon::now(),
            ]);

            $dispute->setRawAttributes($locked->getAttributes(), true);
        });

        // AGENT_BRIEF rule 3: never inside the transaction.
        event(new DisputeStatusChanged(
            disputeId: (int) $dispute->id,
            caseNumber: $dispute->case_number,
            fromStatus: $current->value,
            toStatus: $target->value,
            actorType: $context->actorType->value,
            actorUserId: $context->actorUserId,
            claimantOrgId: $dispute->claimant_org_id,
            respondentOrgId: $dispute->respondent_org_id,
        ));

        return $dispute;
    }

    /** Records something that happened without changing the status. */
    public function note(DisputeModel $dispute, TransitionContext $context): void
    {
        DisputeTimelineModel::query()->create([
            'dispute_id' => $dispute->id,
            'actor_type' => $context->actorType->value,
            'actor_user_id' => $context->actorUserId,
            'actor_org_id' => $context->actorOrgId,
            'action' => $context->action !== '' ? $context->action : 'NOTE',
            'message' => $context->message,
            'from_status' => $dispute->status,
            'to_status' => null,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * Deadline columns implied by entering a state (§13.6).
     *
     * Leaving a timed state clears its clock, so a case sitting in mediation
     * cannot be swept up by the deadline job.
     *
     * @return array<string, ?Carbon>
     */
    private function deadlinesFor(DisputeStatus $target): array
    {
        $replyHours = (int) config('goldb2b.dispute.reply_deadline_hours', 24);
        $negotiationHours = (int) config('goldb2b.dispute.negotiation_hours', 48);

        return match ($target) {
            DisputeStatus::AWAITING_REPLY => [
                'reply_deadline_at' => Carbon::now()->addHours($replyHours),
                'negotiation_deadline_at' => null,
            ],
            DisputeStatus::NEGOTIATION => [
                'reply_deadline_at' => null,
                'negotiation_deadline_at' => Carbon::now()->addHours($negotiationHours),
            ],
            default => [
                'reply_deadline_at' => null,
                'negotiation_deadline_at' => null,
            ],
        };
    }
}
