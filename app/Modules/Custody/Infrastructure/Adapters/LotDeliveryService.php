<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Adapters;

use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Custody\Application\LotOwnershipService;
use App\Modules\Custody\Application\LotStateMachine;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Contracts\DTO\AllocationItem;
use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Contracts\DTO\LotDeliveryResult;
use App\Modules\Custody\Contracts\LotDeliveryInterface;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\LotNotOwnedException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Shared\ValueObjects\FineWeight;
use Illuminate\Support\Facades\DB;

/**
 * The published write side of custody — LotDeliveryInterface over Custody's own
 * services.
 *
 * Everything it does, it does inside one transaction, locking rows in ascending
 * lot id order (AGENT_BRIEF rules 4 and 5): a delivery that split a lot and
 * then failed to move a sibling must leave no half-cut metal behind.
 *
 * What it deliberately never writes is custodian_type, custodian_id or
 * physical_location. Delivery is a change of owner and nothing else — that
 * separation is ADR-005 and the reason the custody layer pays for itself. The
 * before/after snapshots on LotDeliveryResult are taken from the rows
 * themselves so a caller can prove it rather than trust it.
 */
final readonly class LotDeliveryService implements LotDeliveryInterface
{
    public function __construct(
        private LotOwnershipService $ownership,
        private SplitService $splits,
        private LotStateMachine $lotStates,
    ) {}

    public function deliver(
        AllocationPlan $plan,
        int $fromOrganizationId,
        int $toOrganizationId,
        string $referenceType,
        int $referenceId,
        ?int $actorUserId = null,
    ): LotDeliveryResult {
        return DB::transaction(function () use (
            $plan,
            $fromOrganizationId,
            $toOrganizationId,
            $referenceType,
            $referenceId,
            $actorUserId,
        ): LotDeliveryResult {
            // AllocationPlan::lotIds() is already ascending; sort defensively so
            // the lock order does not depend on another module's promise.
            $planned = $plan->lotIds();
            sort($planned);

            $locked = $this->lockAscending($planned);
            $before = $this->custodyOf($locked);

            $this->assertSenderOwnsEverything($planned, $locked, $fromOrganizationId);

            $deliver = $plan->wholeLotIds();
            $created = [];
            $remainder = null;
            $splitPerformed = false;

            $partial = $plan->splitItem();

            if ($partial instanceof AllocationItem) {
                [$deliveredChild, $remainder, $created] = $this->split(
                    $partial,
                    $referenceType,
                    $referenceId,
                    $actorUserId,
                );

                $deliver[] = $deliveredChild;
                $splitPerformed = true;
            }

            sort($deliver);

            $deliveredFineMg = 0;

            foreach ($deliver as $lotId) {
                $lot = $this->ownership->transfer(
                    lotId: $lotId,
                    fromOrganizationId: $fromOrganizationId,
                    toOrganizationId: $toOrganizationId,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    actorUserId: $actorUserId,
                );

                $deliveredFineMg += (int) $lot->fine_weight_mg;
            }

            $after = $this->custodyOf($this->lockAscending($deliver));

            return new LotDeliveryResult(
                deliveredLotIds: array_values($deliver),
                createdLotIds: $created,
                remainderLotId: $remainder,
                deliveredFineMg: $deliveredFineMg,
                splitPerformed: $splitPerformed,
                custodianTypeBefore: $before['custodian_type'],
                custodianIdBefore: $before['custodian_id'],
                locationBefore: $before['location'],
                custodianTypeAfter: $after['custodian_type'],
                custodianIdAfter: $after['custodian_id'],
                locationAfter: $after['location'],
            );
        });
    }

    public function returnToOwner(
        array $lotIds,
        int $fromOrganizationId,
        int $toOrganizationId,
        string $referenceType,
        int $referenceId,
        ?int $actorUserId = null,
    ): LotDeliveryResult {
        return DB::transaction(function () use (
            $lotIds,
            $fromOrganizationId,
            $toOrganizationId,
            $referenceType,
            $referenceId,
            $actorUserId,
        ): LotDeliveryResult {
            $ids = array_values(array_unique(array_map('intval', $lotIds)));
            sort($ids);

            $locked = $this->lockAscending($ids);
            $before = $this->custodyOf($locked);

            $moved = [];
            $movedFineMg = 0;

            foreach ($ids as $lotId) {
                $lot = $locked[$lotId] ?? null;

                // A lot that has since been split or melted is CONSUMED, one
                // that left the platform is WITHDRAWN. Neither can come back,
                // and neither is a reason to abandon the rest of the reversal —
                // §5.8 step 4 settles those in cash instead.
                if (! $lot instanceof GoldLotModel
                    || ! $lot->status->isLive()
                    || (int) $lot->owner_organization_id !== $fromOrganizationId) {
                    continue;
                }

                $returned = $this->ownership->transfer(
                    lotId: $lotId,
                    fromOrganizationId: $fromOrganizationId,
                    toOrganizationId: $toOrganizationId,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    actorUserId: $actorUserId,
                );

                $moved[] = $lotId;
                $movedFineMg += (int) $returned->fine_weight_mg;
            }

            $after = $this->custodyOf($this->lockAscending($ids));

            return new LotDeliveryResult(
                deliveredLotIds: $moved,
                deliveredFineMg: $movedFineMg,
                custodianTypeBefore: $before['custodian_type'],
                custodianIdBefore: $before['custodian_id'],
                locationBefore: $before['location'],
                custodianTypeAfter: $after['custodian_type'],
                custodianIdAfter: $after['custodian_id'],
                locationAfter: $after['location'],
            );
        });
    }

    /**
     * Cut the one partial lot into the piece being delivered and the remainder
     * that stays with the sender.
     *
     * SplitService owns the conservation invariants (Σ children fine + loss ==
     * parent fine) and refuses to commit if they do not hold, so nothing needs
     * re-checking here.
     *
     * @return array{0: int, 1: ?int, 2: list<int>} delivered child, retained child, all children
     */
    private function split(
        AllocationItem $item,
        string $referenceType,
        int $referenceId,
        ?int $actorUserId,
    ): array {
        $lot = GoldLotModel::query()->whereKey($item->lotId)->lockForUpdate()->first();

        if (! $lot instanceof GoldLotModel) {
            throw new CustodyEntityNotFoundException('GoldLot', $item->lotId);
        }

        // A lot locked for a trade or a settlement has no edge to CONSUMED, so
        // it comes back to AVAILABLE for the duration of the cut.
        if ($lot->status === LotStatus::IN_SETTLEMENT || $lot->status === LotStatus::RESERVED) {
            $this->lotStates->transition(
                $lot,
                LotStatus::AVAILABLE,
                new TransitionContext(
                    actorUserId: $actorUserId,
                    reason: 'Splitting for delivery',
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                ),
            );
        }

        $result = $this->splits->split(new SplitLotCommand(
            parentLotId: $item->lotId,
            parts: [SplitPart::byFine(FineWeight::fromMilligrams($item->useFineMg))],
            requestedByUserId: $actorUserId ?? 0,
            remainderChild: true,
            reason: 'Delivery',
            referenceType: $referenceType,
            referenceId: $referenceId,
        ));

        $children = array_map('intval', $result->childLotIds);

        return [$children[0], $children[1] ?? null, array_values($children)];
    }

    /**
     * Lock every row that still exists, one at a time, in ascending id order.
     *
     * One statement per row rather than a whereIn: the lock order is then the
     * loop order and does not depend on how the engine chose to scan.
     *
     * @param  list<int>  $lotIds
     * @return array<int, GoldLotModel> keyed by lot id, ascending
     */
    private function lockAscending(array $lotIds): array
    {
        $ordered = array_values(array_unique(array_map('intval', $lotIds)));
        sort($ordered);

        $locked = [];

        foreach ($ordered as $lotId) {
            $lot = GoldLotModel::query()->whereKey($lotId)->lockForUpdate()->first();

            if ($lot instanceof GoldLotModel) {
                $locked[$lotId] = $lot;
            }
        }

        return $locked;
    }

    /**
     * Assert the sender owns every lot the plan names, before anything is cut.
     *
     * LotOwnershipService would refuse a foreign lot on its own, but only after
     * the split had already run; checking up front means a plan naming someone
     * else's metal never mutates a row at all.
     *
     * @param  list<int>  $planned
     * @param  array<int, GoldLotModel>  $locked
     */
    private function assertSenderOwnsEverything(array $planned, array $locked, int $fromOrganizationId): void
    {
        foreach ($planned as $lotId) {
            $lot = $locked[$lotId] ?? null;

            if (! $lot instanceof GoldLotModel) {
                throw new CustodyEntityNotFoundException('GoldLot', $lotId);
            }

            if ((int) $lot->owner_organization_id !== $fromOrganizationId) {
                throw new LotNotOwnedException($lotId, $fromOrganizationId);
            }
        }
    }

    /**
     * Custodian and location as recorded on the first surviving lot.
     *
     * One lot is enough: the allocator never mixes custodian types within a
     * plan, and this is an attestation for the audit trail, not a join table.
     *
     * @param  array<int, GoldLotModel>  $lots
     * @return array{custodian_type: ?string, custodian_id: ?int, location: ?string}
     */
    private function custodyOf(array $lots): array
    {
        foreach ($lots as $lot) {
            return [
                'custodian_type' => $lot->custodian_type->value,
                'custodian_id' => (int) $lot->custodian_id,
                'location' => $lot->physical_location,
            ];
        }

        return ['custodian_type' => null, 'custodian_id' => null, 'location' => null];
    }
}
