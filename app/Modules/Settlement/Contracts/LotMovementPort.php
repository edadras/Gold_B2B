<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;

/**
 * The write side of custody, in Settlement's vocabulary.
 *
 * The port exists so Settlement's services depend on "deliver this plan for
 * settlement 42" rather than on Custody's own shape. Custody publishes the
 * capability itself as Custody\Contracts\LotDeliveryInterface — a generic
 * (referenceType, referenceId) contract that knows nothing about settlements —
 * and Infrastructure\CustodyLotMovementAdapter is the thin translation between
 * the two. That adapter is the default binding, so in production a settled
 * settlement really does move the gold_lots rows.
 *
 * NullLotMovementPort remains selectable through
 * config('goldb2b.settlement.lot_movement_port') for a deployment that runs
 * Settlement without Custody: the ledger still moves the gold, lot ownership
 * does not follow, and isOperational() reports false so the difference is
 * visible.
 */
interface LotMovementPort
{
    /**
     * Carry out an allocation plan: split the one partial lot if the plan needs
     * it, then move every delivered lot to the receiver.
     *
     * Implementations must leave custodian and physical location untouched —
     * ownership transfer is not a physical operation.
     */
    public function deliver(
        AllocationPlan $plan,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult;

    /** Undo a delivery after a reversal: the lots go back to the original owner. */
    public function returnToOwner(
        array $lotIds,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult;

    /** False when no real custody implementation is wired up. */
    public function isOperational(): bool;
}
