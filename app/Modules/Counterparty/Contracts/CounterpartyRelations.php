<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

use App\Modules\Counterparty\Domain\MovementKind;
use DateTimeInterface;

/**
 * The only surface other modules may use to read or move a bilateral relation.
 *
 * Trading calls `isBlocked()` when filtering an order book match (§10.7) and
 * `autoAcceptsOtc()` when deciding whether a quote can be taken without a
 * human; Settlement drives `applyTrade()` through a domain event rather than
 * calling in directly, so that this module stays a leaf in the dependency graph.
 */
interface CounterpartyRelations
{
    /**
     * Move both sides of a pair by the given deltas, expressed from
     * `$organizationId`'s point of view (positive = the counterparty now owes
     * us more). The mirror row receives the negated amounts, atomically.
     */
    public function applyTrade(
        int $organizationId,
        int $counterpartyOrgId,
        int $goldDeltaMg,
        int $rialDelta,
        ?string $reference = null,
        MovementKind $kind = MovementKind::TRADE,
        ?DateTimeInterface $occurredAt = null,
        ?string $description = null,
    ): void;

    public function snapshot(int $organizationId, int $counterpartyOrgId): ?RelationSnapshot;

    public function isBlocked(int $organizationId, int $counterpartyOrgId): bool;

    public function isTrusted(int $organizationId, int $counterpartyOrgId): bool;

    public function autoAcceptsOtc(int $organizationId, int $counterpartyOrgId): bool;

    /**
     * Counterparty org ids this organisation refuses to deal with, for the
     * matching-engine filter of §10.7.
     *
     * @return list<int>
     */
    public function blockedCounterparties(int $organizationId): array;
}
