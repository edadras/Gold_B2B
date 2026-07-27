<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\DisputeAdminPort;
use App\Modules\Admin\Contracts\DisputeInvestigationContext;
use App\Modules\Admin\Contracts\DisputeRow;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;

/**
 * The dispute queue (§1.8, «DisputeQueue»).
 *
 * The panel assigns a mediator and reads the case file. It does not decide
 * disputes: awarding gold or rial moves the ledger, and that path belongs to
 * Dispute's own resolution service where the reversal legs are posted under the
 * state machine's supervision.
 */
final class DisputeCaseService
{
    public function __construct(
        private readonly DisputeAdminPort $disputes,
        private readonly AdminAuditor $auditor,
    ) {}

    /**
     * @param  list<string>  $statuses
     * @return list<DisputeRow>
     */
    public function queue(array $statuses = [], int $limit = 100): array
    {
        return $this->disputes->queue($statuses, $limit);
    }

    public function openCase(int $disputeId, int $staffUserId): DisputeInvestigationContext
    {
        $context = $this->disputes->investigationContext($disputeId);

        if ($context === null) {
            throw new OperationNotPermittedException('پرونده اختلاف یافت نشد.');
        }

        // A dispute file contains both members' trade and settlement detail —
        // each party's data is sensitive to the other, so reading it is logged.
        $this->auditor->viewSensitive(
            subjectType: 'Dispute',
            subjectId: $disputeId,
            fields: ['trade', 'settlement', 'evidence', 'timeline'],
            organizationId: $context->dispute->claimantOrgId,
            actorId: $staffUserId,
        );

        return $context;
    }

    public function assignMediator(int $disputeId, int $mediatorUserId, int $actorUserId, string $note): void
    {
        if (trim($note) === '') {
            throw new OperationNotPermittedException('تعیین میانجی بدون یادداشت مجاز نیست.');
        }

        $before = $this->disputes->find($disputeId);

        if ($before === null) {
            throw new OperationNotPermittedException('پرونده اختلاف یافت نشد.');
        }

        $this->disputes->assignMediator($disputeId, $mediatorUserId, $actorUserId, $note);

        $this->auditor->action(
            action: 'admin.dispute.mediator_assigned',
            subjectType: 'Dispute',
            subjectId: $disputeId,
            note: $note,
            before: ['status' => $before->status, 'mediator_user_id' => $before->mediatorUserId],
            after: ['status' => 'UNDER_MEDIATION', 'mediator_user_id' => $mediatorUserId],
            organizationId: $before->claimantOrgId,
            actorId: $actorUserId,
        );
    }
}
