<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Contracts\LotAllocatorInterface;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\Contracts\LotMovementResult;
use App\Modules\Settlement\Domain\DeliveryMethod;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\GoldTransferred;
use App\Modules\Settlement\Infrastructure\Models\GoldTransferModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\ValueObjects\FineWeight;
use Illuminate\Support\Facades\DB;

/**
 * The delivery half of a settlement — §5.3 patterns 3 and 4.
 *
 * The allocator picks which lots satisfy the promised fine weight (exact match
 * first, then FIFO, at most one split), the port carries the plan out, and the
 * state machine posts the ledger leg. Worked example 1's group g6.
 *
 * Pattern 4 is the case that matters, and the reason the custody layer exists
 * at all: when the metal is in a vault and stays in that vault, delivery is a
 * change of owner_organization_id and nothing else. custodian, vault box and
 * physical_location are identical before and after; no gold is boxed, insured,
 * driven across Tehran or re-weighed. Seconds instead of days, and zero
 * transport risk. The gold_transfers row records both sides of that so the
 * claim is auditable, and physical_movement stays 0 — a CHECK constraint on
 * the table refuses a CUSTODY_CHANGE row that claims otherwise.
 */
final readonly class GoldTransferService
{
    public function __construct(
        private SettlementStateMachine $stateMachine,
        private LotAllocatorInterface $allocator,
        private GoldLotRepositoryInterface $lots,
        private LotMovementPort $lotMovement,
    ) {}

    /**
     * Deliver the gold and settle: GOLD_TRANSFERRING → SETTLED.
     *
     * @param  ?int  $actorUserId  the custodian operator or the receiving member
     *                             who confirmed the handover (§5.1 gold_confirmed_by)
     */
    public function transfer(int $settlementId, ?int $actorUserId = null): SettlementModel
    {
        /** @var array{0: SettlementModel, 1: GoldTransferModel, 2: ?string} $result */
        $result = DB::transaction(function () use ($settlementId, $actorUserId): array {
            $settlement = $this->stateMachine->lock($settlementId);

            if ($settlement->status !== SettlementStatus::GOLD_TRANSFERRING) {
                throw new OperationNotPermittedException(
                    'Gold can only be delivered from GOLD_TRANSFERRING; settlement is '
                        .$settlement->status->value
                );
            }

            $plan = $this->allocator->allocate(
                $settlement->gold_deliverer_org_id,
                FineWeight::fromMilligrams($settlement->fine_weight_mg),
            );

            $before = $this->custodySnapshot($plan);

            $movement = $this->lotMovement->deliver(
                $plan,
                $settlement->gold_deliverer_org_id,
                $settlement->gold_receiver_org_id,
                $settlementId,
                $actorUserId,
            );

            $after = $this->custodySnapshotOf($movement->deliveredLotIds);
            $method = $this->deliveryMethodFor($settlement, $before, $after, $movement);

            $group = $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::SETTLED,
                new TransitionContext(
                    actorUserId: $actorUserId,
                    reason: 'Gold delivered',
                    metadata: [
                        'lot_ids' => $movement->deliveredLotIds,
                        'split_performed' => $movement->splitPerformed,
                        'delivery_method' => $method->value,
                    ],
                ),
            );

            $transfer = GoldTransferModel::query()->create([
                'settlement_id' => $settlementId,
                'from_organization_id' => $settlement->gold_deliverer_org_id,
                'to_organization_id' => $settlement->gold_receiver_org_id,
                'fine_weight_mg' => $settlement->fine_weight_mg,
                'delivery_method' => $method->value,
                'lot_ids' => $movement->deliveredLotIds,
                'lot_count' => $movement->lotCount(),
                'split_performed' => $movement->splitPerformed,
                'custodian_type_before' => $before['custodian_type'],
                'custodian_id_before' => $before['custodian_id'],
                'location_before' => $before['location'],
                'custodian_type_after' => $after['custodian_type'] ?? $before['custodian_type'],
                'custodian_id_after' => $after['custodian_id'] ?? $before['custodian_id'],
                'location_after' => $after['location'] ?? $before['location'],
                'physical_movement' => $method->movesMetal() && $movement->physicalMovement,
                'transferred_at' => now(),
                'confirmed_by_user_id' => $actorUserId,
                'transaction_group' => $group,
            ]);

            $fresh = $this->stateMachine->lock($settlementId);
            $fresh->allocated_lot_ids = $movement->deliveredLotIds;
            $fresh->delivery_method = $method;
            $fresh->gold_transferred_at = now();
            $fresh->gold_confirmed_by_user_id = $actorUserId;
            $fresh->save();

            return [$fresh, $transfer, $group];
        }, attempts: 3);

        [$settlement, $transfer, $group] = $result;

        event(new GoldTransferred(
            settlementId: (int) $settlement->id,
            goldTransferId: (int) $transfer->id,
            fromOrganizationId: $settlement->gold_deliverer_org_id,
            toOrganizationId: $settlement->gold_receiver_org_id,
            fineWeightMg: $settlement->fine_weight_mg,
            deliveryMethod: $settlement->delivery_method->value,
            lotIds: $transfer->lotIds(),
            splitPerformed: (bool) $transfer->split_performed,
            physicalMovement: (bool) $transfer->physical_movement,
            transactionGroup: $group,
            occurredAt: now()->toIso8601String(),
        ));

        return $settlement;
    }

    /**
     * Which pattern of §5.3 this delivery followed.
     *
     * A vault that stays the same vault is CUSTODY_CHANGE (pattern 4). A move
     * between vaults is VAULT_TRANSFER, and metal leaving custody altogether is
     * PHYSICAL_HANDOVER (pattern 3, which needs the one-time code). A
     * settlement already marked NETTED keeps that: its delivery was discharged
     * by a batch, not by a lot.
     *
     * @param  array{custodian_type: ?string, custodian_id: ?int, location: ?string}  $before
     * @param  array{custodian_type: ?string, custodian_id: ?int, location: ?string}  $after
     */
    private function deliveryMethodFor(
        SettlementModel $settlement,
        array $before,
        array $after,
        LotMovementResult $movement,
    ): DeliveryMethod {
        if ($settlement->delivery_method === DeliveryMethod::NETTED) {
            return DeliveryMethod::NETTED;
        }

        $stayedPut = $before['custodian_type'] === ($after['custodian_type'] ?? $before['custodian_type'])
            && $before['custodian_id'] === ($after['custodian_id'] ?? $before['custodian_id'])
            && ! $movement->physicalMovement;

        if ($before['custodian_type'] === CustodianType::VAULT->value && $stayedPut) {
            return DeliveryMethod::CUSTODY_CHANGE;
        }

        if ($before['custodian_type'] === CustodianType::VAULT->value) {
            return DeliveryMethod::VAULT_TRANSFER;
        }

        return DeliveryMethod::PHYSICAL_HANDOVER;
    }

    /** @return array{custodian_type: ?string, custodian_id: ?int, location: ?string} */
    private function custodySnapshot(AllocationPlan $plan): array
    {
        return $this->custodySnapshotOf($plan->lotIds());
    }

    /**
     * Custody as recorded on the first lot of the delivery. One lot is enough:
     * the allocator only ever mixes lots of the same custodian type, and this
     * is an attestation for the audit trail, not a join table.
     *
     * @param  list<int>  $lotIds
     * @return array{custodian_type: ?string, custodian_id: ?int, location: ?string}
     */
    private function custodySnapshotOf(array $lotIds): array
    {
        foreach ($lotIds as $lotId) {
            $lot = $this->lots->find($lotId);

            if ($lot !== null) {
                return [
                    'custodian_type' => $lot->custodianType->value,
                    'custodian_id' => $lot->custodianId,
                    'location' => $lot->physicalLocation,
                ];
            }
        }

        return ['custodian_type' => null, 'custodian_id' => null, 'location' => null];
    }
}
