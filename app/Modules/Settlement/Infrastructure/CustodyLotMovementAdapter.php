<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Contracts\DTO\LotDeliveryResult;
use App\Modules\Custody\Contracts\LotDeliveryInterface;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\Contracts\LotMovementResult;

/**
 * The production LotMovementPort: Settlement's vocabulary on one side, Custody's
 * published write contract on the other.
 *
 * Everything that actually touches a gold_lots row happens inside Custody, in
 * one transaction, with rows locked in ascending id order. All this class does
 * is name the reference — Settlement knows it is settling settlement 42, Custody
 * only ever sees the pair ('settlement', 42) — and translate the result.
 *
 * The import is legal because LotDeliveryInterface lives in Custody\Contracts:
 * this is the public surface, not a reach into Custody\Application.
 *
 * isOperational() is true: unlike NullLotMovementPort, ownership really follows
 * the ledger here.
 */
final readonly class CustodyLotMovementAdapter implements LotMovementPort
{
    public function __construct(private LotDeliveryInterface $delivery) {}

    public function deliver(
        AllocationPlan $plan,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult {
        return $this->translate($this->delivery->deliver(
            plan: $plan,
            fromOrganizationId: $fromOrganizationId,
            toOrganizationId: $toOrganizationId,
            referenceType: LedgerReference::SETTLEMENT,
            referenceId: $settlementId,
            actorUserId: $actorUserId,
        ));
    }

    public function returnToOwner(
        array $lotIds,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult {
        return $this->translate($this->delivery->returnToOwner(
            lotIds: array_values(array_map('intval', $lotIds)),
            fromOrganizationId: $fromOrganizationId,
            toOrganizationId: $toOrganizationId,
            referenceType: LedgerReference::SETTLEMENT,
            referenceId: $settlementId,
            actorUserId: $actorUserId,
        ));
    }

    public function isOperational(): bool
    {
        return true;
    }

    private function translate(LotDeliveryResult $result): LotMovementResult
    {
        return new LotMovementResult(
            deliveredLotIds: $result->deliveredLotIds,
            retainedLotIds: $result->retainedLotIds(),
            splitPerformed: $result->splitPerformed,
            // Ownership transfer never moves metal — §5.3 pattern 4 — and the
            // before/after custody snapshots below are what proves it.
            physicalMovement: false,
            custodianTypeBefore: $result->custodianTypeBefore,
            custodianIdBefore: $result->custodianIdBefore,
            locationBefore: $result->locationBefore,
            custodianTypeAfter: $result->custodianTypeAfter,
            custodianIdAfter: $result->custodianIdAfter,
            locationAfter: $result->locationAfter,
        );
    }
}
