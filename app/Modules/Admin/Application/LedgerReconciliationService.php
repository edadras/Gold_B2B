<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\AccountRebuild;
use App\Modules\Admin\Contracts\LedgerAdminPort;
use App\Modules\Admin\Contracts\ReconciliationReport;

/**
 * The reconciliation screen (§1.8, «LedgerReconciliation»).
 *
 * Every method is a read. A reconciliation that repaired a ledger would destroy
 * the evidence of what went wrong, so the screen reports and a human decides —
 * and if the decision is to correct, that goes through ManualAdjustmentService
 * and its dual control, not through here.
 */
final class LedgerReconciliationService
{
    public function __construct(private readonly LedgerAdminPort $ledger) {}

    public function report(int $limit = 100): ReconciliationReport
    {
        return $this->ledger->reconcile($limit);
    }

    public function discrepancyCount(): int
    {
        return $this->ledger->discrepancyCount();
    }

    /** @return list<AccountRebuild> */
    public function rebuildForOrganization(int $organizationId): array
    {
        return $this->ledger->rebuildForOrganization($organizationId);
    }

    public function rebuildAccount(int $accountId): ?AccountRebuild
    {
        return $this->ledger->rebuildAccount($accountId);
    }

    public function lastRunAt(): ?string
    {
        return $this->ledger->lastReconciliationAt();
    }
}
