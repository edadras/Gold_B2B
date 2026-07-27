<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

/**
 * Read access to settlements for Accounting, Dispute and Reporting.
 *
 * Returns snapshots only. Anything that changes a settlement goes through the
 * module's own services, so the state machine is never bypassed.
 */
interface SettlementReaderInterface
{
    public function find(int $settlementId): ?SettlementSnapshot;

    public function findByCode(string $settlementCode): ?SettlementSnapshot;

    /** @return list<SettlementSnapshot> settlements created by this trade, oldest first */
    public function forTrade(int $tradeId): array;

    /** @return list<SettlementSnapshot> still-open obligations of an organisation */
    public function openForOrganization(int $organizationId): array;

    /** True while the organisation has any settlement that is not COMPLETED / CANCELLED / REVERSED. */
    public function hasOpenSettlements(int $organizationId): bool;
}
