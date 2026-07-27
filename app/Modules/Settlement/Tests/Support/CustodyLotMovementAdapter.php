<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Support;

use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Custody\Application\LotOwnershipService;
use App\Modules\Custody\Application\LotStateMachine;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Contracts\DTO\AllocationItem;
use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\Contracts\LotMovementResult;
use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * A real LotMovementPort over Custody's services, for the test suite.
 *
 * It lives under Tests/ on purpose. Custody's public contracts are read-only,
 * so production code in Settlement/ may not import LotOwnershipService or
 * SplitService — tests/Architecture/ArchitectureTest.php exempts Tests/ from
 * the encapsulation rule precisely so a fixture can wire two modules together
 * without either of them depending on the other's internals.
 *
 * That means the worked-example assertions run against the genuine Custody
 * implementation: a real split, a real ownership transfer, and a real
 * gold_lots row whose custodian and physical_location can be compared before
 * and after. Nothing about the §5.3 pattern 4 claim is mocked.
 *
 * To make this the production behaviour, either point
 * config('goldb2b.settlement.lot_movement_port') at a copy of this class placed
 * outside app/Modules, or have Custody publish a write contract that Settlement
 * may legally import.
 */
final readonly class CustodyLotMovementAdapter implements LotMovementPort
{
    public function __construct(
        private LotOwnershipService $ownership,
        private SplitService $splits,
        private LotStateMachine $lotStates,
        private GoldLotRepositoryInterface $lots,
    ) {}

    public function deliver(
        AllocationPlan $plan,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult {
        $before = $this->custodyOf($plan->lotIds());

        $deliver = $plan->wholeLotIds();
        $retained = [];
        $splitPerformed = false;

        $partial = $plan->splitItem();

        if ($partial instanceof AllocationItem) {
            [$deliveredChild, $remainderChild] = $this->split($partial, $actorUserId, $settlementId);
            $deliver[] = $deliveredChild;

            if ($remainderChild !== null) {
                $retained[] = $remainderChild;
            }

            $splitPerformed = true;
        }

        sort($deliver);

        foreach ($deliver as $lotId) {
            $this->ownership->transfer(
                lotId: $lotId,
                fromOrganizationId: $fromOrganizationId,
                toOrganizationId: $toOrganizationId,
                referenceType: LedgerReference::SETTLEMENT,
                referenceId: $settlementId,
                actorUserId: $actorUserId,
            );
        }

        $after = $this->custodyOf($deliver);

        return new LotMovementResult(
            deliveredLotIds: array_values($deliver),
            retainedLotIds: $retained,
            splitPerformed: $splitPerformed,
            // Ownership transfer never moves metal — that is the whole point of
            // §5.3 pattern 4, and the after-snapshot below proves it.
            physicalMovement: false,
            custodianTypeBefore: $before['custodian_type'],
            custodianIdBefore: $before['custodian_id'],
            locationBefore: $before['location'],
            custodianTypeAfter: $after['custodian_type'],
            custodianIdAfter: $after['custodian_id'],
            locationAfter: $after['location'],
        );
    }

    public function returnToOwner(
        array $lotIds,
        int $fromOrganizationId,
        int $toOrganizationId,
        int $settlementId,
        ?int $actorUserId = null,
    ): LotMovementResult {
        $moved = [];

        foreach ($lotIds as $lotId) {
            $lot = $this->lots->find((int) $lotId);

            // A lot that has since been split, melted or withdrawn cannot come
            // back; §5.8 step 4 settles those in cash instead.
            if ($lot === null || ! $lot->isOwnedBy($fromOrganizationId)) {
                continue;
            }

            $this->ownership->transfer(
                lotId: (int) $lotId,
                fromOrganizationId: $fromOrganizationId,
                toOrganizationId: $toOrganizationId,
                referenceType: LedgerReference::SETTLEMENT,
                referenceId: $settlementId,
                actorUserId: $actorUserId,
            );

            $moved[] = (int) $lotId;
        }

        return new LotMovementResult(deliveredLotIds: $moved, physicalMovement: false);
    }

    public function isOperational(): bool
    {
        return true;
    }

    /**
     * Cut the one partial lot into the piece being delivered and the remainder
     * that stays with the seller.
     *
     * @return array{0: int, 1: ?int} delivered child, retained child
     */
    private function split(AllocationItem $item, ?int $actorUserId, int $settlementId): array
    {
        // A lot locked for settlement has no edge to CONSUMED, so it comes back
        // to AVAILABLE for the duration of the cut.
        $lot = GoldLotModel::query()->findOrFail($item->lotId);

        if ($lot->status === LotStatus::IN_SETTLEMENT || $lot->status === LotStatus::RESERVED) {
            $this->lotStates->transition(
                $lot,
                LotStatus::AVAILABLE,
                new TransitionContext(
                    actorUserId: $actorUserId,
                    reason: 'Splitting for settlement delivery',
                    referenceType: LedgerReference::SETTLEMENT,
                    referenceId: $settlementId,
                ),
            );
        }

        $result = $this->splits->split(new SplitLotCommand(
            parentLotId: $item->lotId,
            parts: [SplitPart::byFine(FineWeight::fromMilligrams($item->useFineMg))],
            requestedByUserId: $actorUserId ?? 0,
            remainderChild: true,
            reason: 'Settlement delivery',
            referenceType: LedgerReference::SETTLEMENT,
            referenceId: $settlementId,
        ));

        $children = $result->childLotIds;

        return [$children[0], $children[1] ?? null];
    }

    /**
     * @param  list<int>  $lotIds
     * @return array{custodian_type: ?string, custodian_id: ?int, location: ?string}
     */
    private function custodyOf(array $lotIds): array
    {
        foreach ($lotIds as $lotId) {
            $lot = $this->lots->find((int) $lotId);

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
