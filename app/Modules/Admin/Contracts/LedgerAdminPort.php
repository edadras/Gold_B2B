<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Read side of the ledger for the operator panel.
 *
 * Strictly read-only. Writing to the ledger from the admin panel happens only
 * through LedgerAdjustmentPoster, behind dual control.
 */
interface LedgerAdminPort
{
    /** Cheap count for the dashboard's most prominent alert. */
    public function discrepancyCount(): int;

    public function reconcile(int $limit = 100): ReconciliationReport;

    /** @return list<AccountRebuild> */
    public function rebuildForOrganization(int $organizationId): array;

    public function rebuildAccount(int $accountId): ?AccountRebuild;

    /** @return array<string, int> asset => raw balance across every bucket */
    public function balancesFor(int $organizationId): array;

    public function lastReconciliationAt(): ?string;
}
