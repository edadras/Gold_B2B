<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Null;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\Contracts\LotMovementResult;

/**
 * Opt-out LotMovementPort: reports the plan's lots as delivered without
 * touching them.
 *
 * No longer the default — CustodyLotMovementAdapter is — but still selectable
 * through config('goldb2b.settlement.lot_movement_port') for a deployment that
 * runs Settlement without the Custody module. Rather than fail every
 * settlement, the ledger still moves the gold — that is where balances and
 * conservation of mass live — and lot ownership simply does not follow.
 *
 * isOperational() returns false so a caller, an operator dashboard or a health
 * check can tell the difference between "nothing to move" and "nothing wired
 * up".
 */
final class NullLotMovementPort implements LotMovementPort
{
    public function deliver(
        AllocationPlan $plan,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult {
        return new LotMovementResult(
            deliveredLotIds: $plan->lotIds(),
            retainedLotIds: [],
            splitPerformed: false,
            physicalMovement: false,
        );
    }

    public function returnToOwner(
        array $lotIds,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult {
        return new LotMovementResult(
            deliveredLotIds: array_values(array_map('intval', $lotIds)),
            physicalMovement: false,
        );
    }

    public function isOperational(): bool
    {
        return false;
    }
}
