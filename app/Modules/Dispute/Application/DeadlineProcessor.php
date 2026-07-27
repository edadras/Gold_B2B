<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The clock behind §13.6: 24 hours to reply, 48 hours to agree.
 *
 * Both deadlines escalate to the same place. Silence from the respondent and a
 * negotiation that ran out of time are different behaviours but the same
 * conclusion — the parties will not settle this between themselves, so an
 * operator takes it. §2.8 gives AWAITING_REPLY and NEGOTIATION exactly one
 * timeout edge each, both to UNDER_MEDIATION.
 *
 * Escalation is attributed to SYSTEM (§2.14 rule 4) so the timeline never reads
 * as though a person made the decision.
 *
 * One case failing must not stop the sweep: a case that cannot be escalated is
 * recorded and the next one is attempted, otherwise a single bad row would
 * freeze every other member's deadline.
 */
final readonly class DeadlineProcessor
{
    public function __construct(private DisputeStateMachine $stateMachine) {}

    /**
     * Escalate everything whose clock has run out.
     *
     * @return DeadlineSweepResult counts and failures, for the command's output
     */
    public function sweep(?Carbon $now = null): DeadlineSweepResult
    {
        $now ??= Carbon::now();

        $noReply = $this->escalate(
            $this->expiredAwaitingReply($now),
            'NO_REPLY_TIMEOUT',
            'مهلت ۲۴ ساعته پاسخ سپری شد — ارجاع خودکار به میانجی‌گری',
        );

        $noAgreement = $this->escalate(
            $this->expiredNegotiation($now),
            'NEGOTIATION_TIMEOUT',
            'مهلت ۴۸ ساعته مذاکره سپری شد — ارجاع خودکار به میانجی‌گری',
        );

        return new DeadlineSweepResult(
            noReplyEscalated: $noReply['done'],
            negotiationEscalated: $noAgreement['done'],
            failures: [...$noReply['failures'], ...$noAgreement['failures']],
        );
    }

    /**
     * Cases whose reply deadline is close, for the DISPUTE_REPLY_DUE reminder
     * of docs/03-domain/15-notification-reporting.md §15.2.
     *
     * @return array<int, DisputeModel>
     */
    public function replyDeadlineApproaching(int $withinHours = 4, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return DisputeModel::query()
            ->where('status', DisputeStatus::AWAITING_REPLY->value)
            ->whereNotNull('reply_deadline_at')
            ->whereBetween('reply_deadline_at', [$now, $now->copy()->addHours($withinHours)])
            ->orderBy('reply_deadline_at')
            ->get()
            ->all();
    }

    /** @return array<int, DisputeModel> */
    private function expiredAwaitingReply(Carbon $now): array
    {
        return DisputeModel::query()
            ->where('status', DisputeStatus::AWAITING_REPLY->value)
            ->whereNotNull('reply_deadline_at')
            ->where('reply_deadline_at', '<=', $now)
            ->orderBy('id')          // AGENT_BRIEF rule 4: ascending id order
            ->get()
            ->all();
    }

    /** @return array<int, DisputeModel> */
    private function expiredNegotiation(Carbon $now): array
    {
        return DisputeModel::query()
            ->where('status', DisputeStatus::NEGOTIATION->value)
            ->whereNotNull('negotiation_deadline_at')
            ->where('negotiation_deadline_at', '<=', $now)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, DisputeModel>  $disputes
     * @return array{done: int, failures: array<int, string>}
     */
    private function escalate(array $disputes, string $action, string $message): array
    {
        $done = 0;
        $failures = [];

        foreach ($disputes as $dispute) {
            try {
                $this->stateMachine->transition(
                    $dispute,
                    DisputeStatus::UNDER_MEDIATION,
                    TransitionContext::system($action, $message),
                );

                $done++;
            } catch (Throwable $e) {
                $failures[] = sprintf('%s: %s', $dispute->case_number, $e->getMessage());
            }
        }

        return ['done' => $done, 'failures' => $failures];
    }
}
