<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\LedgerAdminPort;
use App\Modules\Admin\Contracts\OrganizationAdminPort;
use App\Modules\Admin\Contracts\OrganizationRow;
use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;

/**
 * Organisation management (§1.8, «OrganizationResource»).
 *
 * Suspension and restriction are the only writes, they always carry a reason,
 * and they go through Identity's `OrganizationLifecycle` so the state machine
 * validates the move, records the status event and dispatches the domain event
 * that Trading listens to when it cancels the member's open orders.
 *
 * There is no delete. §1.11: «بدون امکان حذف هیچ رکورد مالی» — and an
 * organisation is the root every financial record hangs from.
 */
final class OrganizationAdminService
{
    /** Transitions the panel is allowed to ask for. */
    public const ALLOWED_TRANSITIONS = ['RESTRICTED', 'SUSPENDED', 'ACTIVE'];

    public function __construct(
        private readonly OrganizationAdminPort $organizations,
        private readonly OrganizationLifecycle $lifecycle,
        private readonly LedgerAdminPort $ledger,
        private readonly AdminAuditor $auditor,
    ) {}

    /**
     * @param  list<string>  $statuses
     * @return list<OrganizationRow>
     */
    public function list(?string $search = null, array $statuses = [], int $limit = 100): array
    {
        return $this->organizations->list($search, $statuses, $limit);
    }

    /**
     * @return array{organization: OrganizationRow, balances: array<string, int>}|null
     */
    public function detail(int $organizationId): ?array
    {
        $organization = $this->organizations->find($organizationId);

        if ($organization === null) {
            return null;
        }

        return [
            'organization' => $organization,
            'balances' => $this->ledger->balancesFor($organizationId),
        ];
    }

    public function changeStatus(
        int $organizationId,
        string $target,
        int $actorUserId,
        string $reason,
    ): void {
        if (trim($reason) === '') {
            throw new OperationNotPermittedException('تغییر وضعیت عضو بدون دلیل مجاز نیست.');
        }

        if (! in_array($target, self::ALLOWED_TRANSITIONS, true)) {
            throw new OperationNotPermittedException("تغییر وضعیت به {$target} از این صفحه مجاز نیست.");
        }

        $organization = $this->organizations->find($organizationId);

        if ($organization === null) {
            throw new OperationNotPermittedException('سازمان یافت نشد.');
        }

        $status = OrganizationStatus::from($target);
        $current = $this->lifecycle->currentStatus($organizationId);

        if (! $current->canTransitionTo($status)) {
            $this->auditor->denied(
                'admin.organization.status_change',
                'Organization',
                $organizationId,
                "illegal transition {$current->value} -> {$target}",
                $actorUserId,
            );

            throw new OperationNotPermittedException(sprintf(
                'گذار از %s به %s مجاز نیست.',
                $current->value,
                $target,
            ));
        }

        $this->lifecycle->transition(
            organizationId: $organizationId,
            target: $status,
            actorUserId: $actorUserId,
            reason: $reason,
        );

        $this->auditor->action(
            action: 'admin.organization.'.strtolower($target),
            subjectType: 'Organization',
            subjectId: $organizationId,
            note: $reason,
            before: ['status' => $current->value],
            after: ['status' => $target],
            organizationId: $organizationId,
            actorId: $actorUserId,
        );
    }
}
