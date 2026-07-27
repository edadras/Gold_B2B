<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\AmlAdminPort;
use App\Modules\Admin\Contracts\AmlFlagRow;
use App\Modules\Admin\Contracts\AmlInvestigationContext;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;

/**
 * The AML flag queue (§1.8, «AmlFlagQueue»).
 *
 * Sits behind `platform.aml.manage`, which is a *different* permission from
 * general admin access — §1.11 asks for exactly that separation, and the route
 * middleware enforces it. Opening a case reveals a member's trading pattern and
 * balances, so the read is audited like any other sensitive read.
 */
final class AmlCaseService
{
    public function __construct(
        private readonly AmlAdminPort $aml,
        private readonly AdminAuditor $auditor,
    ) {}

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $severities
     * @return list<AmlFlagRow>
     */
    public function queue(array $statuses = [], array $severities = [], int $limit = 100): array
    {
        return $this->aml->flags($statuses, $severities, $limit);
    }

    public function openCase(int $flagId, int $analystUserId): AmlInvestigationContext
    {
        $context = $this->aml->investigationContext($flagId);

        if ($context === null) {
            throw new OperationNotPermittedException('پرچم AML یافت نشد.');
        }

        $this->auditor->viewSensitive(
            subjectType: 'AmlFlag',
            subjectId: $flagId,
            fields: ['recent_trades', 'balances', 'other_flags'],
            organizationId: $context->flag->organizationId,
            actorId: $analystUserId,
        );

        return $context;
    }

    public function decide(
        int $flagId,
        int $analystUserId,
        string $status,
        string $notes,
        ?string $actionTaken = null,
    ): void {
        if (trim($notes) === '') {
            throw new OperationNotPermittedException('تغییر وضعیت پرچم بدون یادداشت مجاز نیست.');
        }

        $before = $this->aml->find($flagId);

        if ($before === null) {
            throw new OperationNotPermittedException('پرچم AML یافت نشد.');
        }

        $this->aml->recordDecision($flagId, $analystUserId, $status, $notes, $actionTaken);

        $this->auditor->action(
            action: 'admin.aml.decision',
            subjectType: 'AmlFlag',
            subjectId: $flagId,
            note: $notes,
            before: ['status' => $before->status],
            after: ['status' => $status, 'action_taken' => $actionTaken],
            organizationId: $before->organizationId,
            actorId: $analystUserId,
        );
    }
}
