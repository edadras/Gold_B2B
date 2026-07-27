<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Domain\ActorType;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\EvidenceType;
use App\Modules\Dispute\Infrastructure\Models\DisputeEvidenceModel;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Evidence filing (§13.2 evidence column, §13.5 dispute_evidences).
 *
 * Two rules worth stating out loud:
 *
 *  - Only the two parties may file, and only while the case is open. Evidence
 *    arriving after the verdict is not evidence, it is an appeal, and this
 *    module has no appeal.
 *  - SYSTEM_LOG cannot be submitted by a member. A member-uploaded "system log"
 *    would carry the authority of the platform without being from it, which is
 *    precisely the confusion an UNAUTHORIZED_TRADE or SYSTEM_ERROR claim turns
 *    on.
 */
final readonly class EvidenceService
{
    public function __construct(private DisputeStateMachine $stateMachine) {}

    public function submit(
        DisputeModel $dispute,
        int $organizationId,
        int $userId,
        EvidenceType $type,
        string $description,
        ?int $documentId = null,
        ?string $fileHash = null,
    ): DisputeEvidenceModel {
        if (! $dispute->involves($organizationId)) {
            throw new OperationNotPermittedException('Only a party to the case may submit evidence');
        }

        if ($dispute->statusEnum()->isFinal()) {
            throw new OperationNotPermittedException('The case is closed; evidence can no longer be filed');
        }

        if ($type->isSystemGenerated()) {
            throw new OperationNotPermittedException(
                'System logs are attached by the platform, not submitted by a member'
            );
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException('Evidence needs a description');
        }

        if ($fileHash !== null && preg_match('/^[0-9a-f]{64}$/i', $fileHash) !== 1) {
            throw new InvalidArgumentException('File hash must be a SHA-256 hex digest');
        }

        /** @var DisputeEvidenceModel $evidence */
        $evidence = DisputeEvidenceModel::query()->create([
            'dispute_id' => $dispute->id,
            'submitted_by_org_id' => $organizationId,
            'submitted_by_user_id' => $userId,
            'evidence_type' => $type->value,
            'document_id' => $documentId,
            'description' => $description,
            'file_hash' => $fileHash === null ? null : strtolower($fileHash),
            'submitted_at' => Carbon::now(),
        ]);

        $this->stateMachine->note($dispute, new TransitionContext(
            actorType: $organizationId === $dispute->claimant_org_id
                ? ActorType::CLAIMANT
                : ActorType::RESPONDENT,
            actorUserId: $userId,
            actorOrgId: $organizationId,
            action: 'EVIDENCE_SUBMITTED',
            message: $type->label().' — '.$description,
        ));

        // Filing what the mediator asked for returns the case to them.
        if ($dispute->statusEnum() === DisputeStatus::AWAITING_EVIDENCE) {
            $this->stateMachine->transition(
                $dispute,
                DisputeStatus::UNDER_MEDIATION,
                TransitionContext::system('EVIDENCE_RECEIVED', 'مدرک درخواستی دریافت شد'),
            );
        }

        return $evidence;
    }

    /** The platform attaching its own record — the one path SYSTEM_LOG allows. */
    public function attachSystemLog(DisputeModel $dispute, string $description): DisputeEvidenceModel
    {
        /** @var DisputeEvidenceModel $evidence */
        $evidence = DisputeEvidenceModel::query()->create([
            'dispute_id' => $dispute->id,
            'submitted_by_org_id' => 0,
            'submitted_by_user_id' => 0,
            'evidence_type' => EvidenceType::SYSTEM_LOG->value,
            'description' => $description,
            'submitted_at' => Carbon::now(),
        ]);

        $this->stateMachine->note(
            $dispute,
            TransitionContext::system('SYSTEM_LOG_ATTACHED', $description),
        );

        return $evidence;
    }

    /** A mediator asking for more (§13.6 «درخواست مدرک تکمیلی»). */
    public function requestMore(DisputeModel $dispute, int $mediatorUserId, string $what): DisputeModel
    {
        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::AWAITING_EVIDENCE,
            TransitionContext::mediator($mediatorUserId, 'EVIDENCE_REQUESTED', $what),
        );
    }

    /** A mediator ordering an independent re-assay (§13.6 «ری‌گیری ثالث»). */
    public function requestThirdPartyReassay(
        DisputeModel $dispute,
        int $mediatorUserId,
        string $note = '',
    ): DisputeModel {
        if (! $dispute->typeEnum()->allowsThirdPartyReassay()) {
            throw new OperationNotPermittedException(
                'A re-assay cannot settle a '.$dispute->typeEnum()->label().' dispute'
            );
        }

        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::AWAITING_REASSAY,
            TransitionContext::mediator(
                $mediatorUserId,
                'REASSAY_REQUESTED',
                $note !== '' ? $note : 'ارسال به آزمایشگاه مستقل TIER_1',
            ),
        );
    }

    /** The re-assay came back; the mediator resumes with a new fact in hand. */
    public function recordReassayResult(
        DisputeModel $dispute,
        int $mediatorUserId,
        int $measuredPurityX10k,
        ?int $laboratoryId = null,
    ): DisputeModel {
        $this->attachSystemLog(
            $dispute,
            sprintf(
                'نتیجه ری‌گیری ثالث: عیار %d%s',
                $measuredPurityX10k,
                $laboratoryId === null ? '' : " — آزمایشگاه {$laboratoryId}",
            ),
        );

        return $this->stateMachine->transition(
            $dispute,
            DisputeStatus::UNDER_MEDIATION,
            TransitionContext::mediator($mediatorUserId, 'REASSAY_COMPLETED'),
        );
    }
}
