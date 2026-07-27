<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Domain\FlagStatus;
use App\Modules\Risk\Infrastructure\Models\AmlFlag;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The AmlFlag state machine of docs/11-appendix/02-state-machines.md §2.11.
 *
 * Appendix §2.14 rule 2: no transition happens without consulting
 * allowedTransitions(); rule 3: a final state never changes again. Every
 * transition requires a note, per §12.7 ("هر اقدام باید یادداشت اجباری داشته باشد").
 */
final class FlagWorkflow
{
    public function assign(AmlFlag $flag, int $reviewerUserId, string $notes): AmlFlag
    {
        return $this->transition($flag, FlagStatus::UNDER_REVIEW, $reviewerUserId, $notes, [
            'assigned_to_user_id' => $reviewerUserId,
        ]);
    }

    public function clear(AmlFlag $flag, int $reviewerUserId, string $notes): AmlFlag
    {
        return $this->transition($flag, FlagStatus::CLEARED, $reviewerUserId, $notes);
    }

    /** The rule itself needs tuning — §12.9 point 3 feeds this back into parameters. */
    public function markFalsePositive(AmlFlag $flag, int $reviewerUserId, string $notes): AmlFlag
    {
        return $this->transition($flag, FlagStatus::FALSE_POSITIVE, $reviewerUserId, $notes);
    }

    public function escalate(AmlFlag $flag, int $reviewerUserId, string $notes): AmlFlag
    {
        return $this->transition($flag, FlagStatus::ESCALATED, $reviewerUserId, $notes);
    }

    public function requireEnhancedReview(AmlFlag $flag, int $reviewerUserId, string $notes): AmlFlag
    {
        return $this->transition($flag, FlagStatus::ENHANCED_REVIEW, $reviewerUserId, $notes);
    }

    public function recordAction(AmlFlag $flag, int $reviewerUserId, string $notes, string $actionTaken): AmlFlag
    {
        return $this->transition($flag, FlagStatus::ACTION_TAKEN, $reviewerUserId, $notes, [
            'action_taken' => $actionTaken,
        ]);
    }

    /**
     * Records a filing with the authorities. Deliberately not a state change:
     * a reported flag keeps whatever review state it is in, and §12.10 forbids
     * anything about it reaching the member.
     */
    public function recordRegulatoryReport(AmlFlag $flag, string $reference): AmlFlag
    {
        return DB::transaction(function () use ($flag, $reference): AmlFlag {
            $flag->forceFill([
                'reported_at' => CarbonImmutable::now(),
                'report_reference' => $reference,
            ])->save();

            return $flag;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws InvalidStateTransitionException
     * @throws OperationNotPermittedException when the mandatory note is missing
     */
    public function transition(
        AmlFlag $flag,
        FlagStatus $target,
        int $reviewerUserId,
        string $notes,
        array $extra = [],
    ): AmlFlag {
        if (trim($notes) === '') {
            throw new OperationNotPermittedException('AML_REVIEW_NOTE_REQUIRED');
        }

        $from = $flag->status;

        if (! $from->canTransitionTo($target)) {
            throw new InvalidStateTransitionException('AmlFlag', $from->value, $target->value);
        }

        return DB::transaction(function () use ($flag, $target, $reviewerUserId, $notes, $extra): AmlFlag {
            $flag->forceFill(array_merge([
                'status' => $target->value,
                'reviewed_at' => CarbonImmutable::now(),
                'reviewed_by_user_id' => $reviewerUserId,
                'resolution_notes' => $this->appendNote($flag->resolution_notes, $notes),
            ], $extra))->save();

            return $flag;
        });
    }

    private function appendNote(?string $existing, string $note): string
    {
        $stamped = CarbonImmutable::now()->toIso8601String().' — '.trim($note);

        return $existing === null || $existing === ''
            ? $stamped
            : $existing."\n".$stamped;
    }
}
