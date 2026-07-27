<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Contracts;

/**
 * Supplies `distinct_counterparties`, which §14.6 notes cannot be maintained by
 * increment: seeing a settlement with member X does not tell you whether X was
 * already counted.
 *
 * It is a port rather than a direct query because the authoritative source is
 * the Counterparty module's relation table, and Reputation may depend only on
 * Shared and Identity. The default implementation reads that table read-only
 * and degrades to zero when it is absent; swapping in a call to a Counterparty
 * API later is a one-line binding change.
 */
interface CounterpartyCounter
{
    public function distinctCounterparties(int $organizationId): int;

    /**
     * Bulk form for the nightly recompute, so one query serves every member.
     *
     * @return array<int, int> organisation id => distinct counterparty count
     */
    public function allDistinctCounterparties(): array;
}
