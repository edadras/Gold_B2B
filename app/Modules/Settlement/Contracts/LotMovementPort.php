<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;

/**
 * The write side of custody, as Settlement needs it.
 *
 * Custody's public surface (LotAllocatorInterface, GoldLotRepositoryInterface)
 * is read-only: it can tell Settlement which lots would satisfy a delivery, but
 * offers no contract for changing a lot's owner or splitting one. The
 * implementations exist — Custody\Application\LotOwnershipService and
 * SplitService — but they live outside Contracts/Events/Domain, so importing
 * them here would break module encapsulation (AGENT_BRIEF rule 7, enforced by
 * tests/Architecture/ArchitectureTest.php).
 *
 * So Settlement declares the port it needs and lets the wiring supply an
 * implementation. NullLotMovementPort is bound by default: the ledger still
 * moves the gold and the settlement still completes, but no lot changes hands
 * until a real adapter is configured through
 * config('goldb2b.settlement.lot_movement_port'). Settlement's own test suite
 * binds one over Custody's services, which is how the worked-example test
 * proves that the lot's owner changes while its custodian and physical
 * location do not.
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
