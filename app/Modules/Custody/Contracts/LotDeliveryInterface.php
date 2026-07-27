<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Contracts\DTO\LotDeliveryResult;

/**
 * The write side of custody, published.
 *
 * LotAllocatorInterface and GoldLotRepositoryInterface let another module ask
 * which lots would satisfy a delivery and read what happened to them. This is
 * the contract that actually carries the delivery out: it cuts the one lot the
 * plan needs cutting and moves owner_organization_id on the rest.
 *
 * Custody owns lot mutation, so Custody publishes it. Without this, a module
 * that legitimately needs to move metal on the books (Settlement, on a
 * completed settlement) either reaches into Custody\Application — which module
 * encapsulation forbids, and tests/Architecture/ArchitectureTest.php enforces —
 * or leaves gold_lots pointing at the previous owner while the ledger says
 * otherwise.
 *
 * The reference is deliberately a generic (type, id) pair. Custody does not
 * know what a settlement is and must not learn: callers pass their own
 * vocabulary and it lands in the custody_operations and lot_status_events audit
 * trail unchanged.
 *
 * Implementations must:
 *   - run inside one transaction and lock rows in ascending lot id order;
 *   - leave custodian_type, custodian_id and physical_location untouched —
 *     ownership transfer is not a physical operation (ADR-005);
 *   - preserve the split conservation invariants (Σ children fine + loss ==
 *     parent fine).
 */
interface LotDeliveryInterface
{
    /**
     * Carry out an allocation plan: split the one partial lot if the plan needs
     * it, then move every delivered lot to the receiver.
     *
     * @param  string  $referenceType  the caller's own reference vocabulary, e.g. 'settlement'
     *
     * @throws \App\Modules\Custody\Domain\Exceptions\LotNotOwnedException if a planned lot is not the sender's
     * @throws \App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException if a planned lot is gone
     */
    public function deliver(
        AllocationPlan $plan,
        int $fromOrganizationId,
        int $toOrganizationId,
        string $referenceType,
        int $referenceId,
        ?int $actorUserId = null,
    ): LotDeliveryResult;

    /**
     * Put lots back with their previous owner after a correction.
     *
     * A lot that has since been split, melted or withdrawn cannot come back;
     * implementations skip it and return the ones that did move, rather than
     * failing the whole reversal — the caller settles the rest another way.
     *
     * @param  list<int>  $lotIds
     */
    public function returnToOwner(
        array $lotIds,
        int $fromOrganizationId,
        int $toOrganizationId,
        string $referenceType,
        int $referenceId,
        ?int $actorUserId = null,
    ): LotDeliveryResult;
}
