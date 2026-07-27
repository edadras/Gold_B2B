<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Results\SplitResult;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Enums\LineageOperation;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\WeightConservationException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Events\GoldLotCreated;
use App\Modules\Custody\Events\LotSplit;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Splits one lot into N children — docs/03-domain/02-gold-lot-assay.md §2.5.
 *
 * Enforced invariants:
 *   1) Σ children gross + gross loss == parent gross
 *   2) Σ children fine  + fine loss  == parent fine
 *   3) children inherit the parent's purity
 *   4) children inherit the parent's owner
 *   5) children inherit the parent's custodian
 *   6) the parent becomes CONSUMED and never comes back
 *   7) one lot_lineage row per child
 *
 * The fine shortfall is reported to the caller instead of being written to the
 * ledger here: Custody must not depend on Ledger.
 */
final readonly class SplitService
{
    public const DEFAULT_MAX_CHILDREN = 50;

    public function __construct(
        private LotCreator $lots,
        private LotStateMachine $stateMachine,
        private CustodyOperationRecorder $recorder,
    ) {}

    public function split(SplitLotCommand $command): SplitResult
    {
        $this->assertPartCount($command);

        /** @var array{result: SplitResult, children: list<GoldLotModel>} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $parent = GoldLotModel::query()
                ->whereKey($command->parentLotId)
                ->lockForUpdate()
                ->first();

            if (! $parent instanceof GoldLotModel) {
                throw new CustodyEntityNotFoundException('GoldLot', $command->parentLotId);
            }

            // Invariant 6 is only reachable from a status that allows CONSUMED.
            if (! $parent->status->canTransitionTo(LotStatus::CONSUMED)) {
                throw new LotNotAvailableException($parent->id, $parent->status, 'SPLIT');
            }

            $plan = $this->planChildren($command, $parent);

            $children = [];
            foreach ($plan['grossMg'] as $index => $grossMg) {
                $children[] = $this->lots->create(new NewLotSpec(
                    ownerOrganizationId: (int) $parent->owner_organization_id,
                    gross: Weight::fromMilligrams($grossMg),
                    purity: $parent->purity(),
                    puritySource: $parent->purity_source,
                    shape: $parent->shape,
                    originType: OriginType::SPLIT,
                    custodianType: $parent->custodian_type,
                    custodianId: (int) $parent->custodian_id,
                    status: $parent->status === LotStatus::UNDER_ASSAY
                        ? LotStatus::UNDER_ASSAY
                        : LotStatus::AVAILABLE,
                    fine: FineWeight::fromMilligrams($plan['fineMg'][$index]),
                    vaultBoxId: $parent->vault_box_id === null ? null : (int) $parent->vault_box_id,
                    physicalLocation: $parent->physical_location,
                    serialNumber: $plan['serials'][$index],
                    hallmarkCode: $parent->hallmark_code,
                    refinerId: $parent->refiner_id === null ? null : (int) $parent->refiner_id,
                    refinedAt: $parent->refined_at?->toDateTimeString(),
                    // The parent's certificate still describes this metal; the
                    // lineage row is what proves the connection.
                    currentAssayId: $parent->current_assay_id === null ? null : (int) $parent->current_assay_id,
                    generation: (int) $parent->generation + 1,
                    metalType: $parent->metal_type,
                    createdByUserId: $command->requestedByUserId,
                ));
            }

            $childIds = array_map(static fn (GoldLotModel $c): int => (int) $c->id, $children);

            $operation = $this->recorder->record(
                type: CustodyOperationType::SPLIT,
                inputLotIds: [(int) $parent->id],
                outputLotIds: $childIds,
                inputFineMg: (int) $parent->fine_weight_mg,
                outputFineMg: array_sum($plan['fineMg']),
                lossFineMg: $plan['fineLossMg'],
                requestedByUserId: $command->requestedByUserId,
                organizationId: (int) $parent->owner_organization_id,
                lossGrossMg: $plan['grossLossMg'],
                fromLocation: $parent->physical_location,
                toLocation: $parent->physical_location,
                reason: $command->reason,
                referenceType: $command->referenceType,
                referenceId: $command->referenceId,
                executedByUserId: $command->requestedByUserId,
            );

            foreach ($childIds as $childId) {
                $this->recorder->linkLineage(
                    parentLotId: (int) $parent->id,
                    childLotId: $childId,
                    operation: LineageOperation::SPLIT,
                    operationId: (int) $operation->id,
                );
            }

            $this->recorder->closeCustodyRecord(
                (int) $parent->id,
                (int) $operation->id,
                $command->requestedByUserId,
            );

            foreach ($children as $child) {
                $this->recorder->openCustodyRecord(
                    $child,
                    (int) $operation->id,
                    $command->requestedByUserId,
                    $this->vaultIdOf($parent),
                );
            }

            $parentGross = (int) $parent->gross_weight_mg;
            $parentFine = (int) $parent->fine_weight_mg;
            $parentCode = (string) $parent->lot_code;
            $ownerId = (int) $parent->owner_organization_id;

            $this->stateMachine->transition(
                $parent,
                LotStatus::CONSUMED,
                (new TransitionContext(
                    actorUserId: $command->requestedByUserId,
                    reason: $command->reason ?? 'Split into '.count($childIds).' children',
                ))->forOperation((int) $operation->id),
            );

            $result = new SplitResult(
                parentLotId: $command->parentLotId,
                parentLotCode: $parentCode,
                ownerOrganizationId: $ownerId,
                childLotIds: $childIds,
                childLotCodes: array_map(static fn (GoldLotModel $c): string => (string) $c->lot_code, $children),
                childGrossMg: $plan['grossMg'],
                childFineMg: $plan['fineMg'],
                parentGrossMg: $parentGross,
                parentFineMg: $parentFine,
                grossLossMg: $plan['grossLossMg'],
                fineLossMg: $plan['fineLossMg'],
                operationId: (int) $operation->id,
            );

            // Last line of defence — if this ever fires the arithmetic above is
            // wrong and the transaction must not commit.
            $this->assertConservation($result);

            return ['result' => $result, 'children' => $children];
        }, attempts: 3);

        $this->dispatchEvents($outcome['result'], $outcome['children'], $command);

        return $outcome['result'];
    }

    /**
     * Resolve requested parts into concrete child weights.
     *
     * @return array{grossMg: list<int>, fineMg: list<int>, serials: list<string|null>, grossLossMg: int, fineLossMg: int}
     */
    private function planChildren(SplitLotCommand $command, GoldLotModel $parent): array
    {
        $parentGross = (int) $parent->gross_weight_mg;
        $parentFine = (int) $parent->fine_weight_mg;
        $purity = $parent->purity();

        $usableGross = IntMath::sub($parentGross, $command->physicalLossGrossMg);

        if ($usableGross <= 0) {
            throw new InvalidLotOperationException('SPLIT', 'physical loss consumes the whole lot', [
                'parent_gross_mg' => $parentGross,
                'physical_loss_mg' => $command->physicalLossGrossMg,
            ]);
        }

        $grossMg = [];
        $serials = [];

        foreach ($command->parts as $part) {
            $resolved = $part->resolveGrossMg($purity);

            if ($resolved <= 0) {
                throw new InvalidLotOperationException('SPLIT', 'a child part resolves to zero gross weight');
            }

            $grossMg[] = $resolved;
            $serials[] = $part->serialNumber;
        }

        $requested = IntMath::sum($grossMg);

        if ($requested > $usableGross) {
            throw new InvalidLotOperationException('SPLIT', 'requested children exceed the parent weight', [
                'requested_gross_mg' => $requested,
                'available_gross_mg' => $usableGross,
            ]);
        }

        $remainder = $usableGross - $requested;

        if ($remainder > 0) {
            if (! $command->remainderChild) {
                throw new InvalidLotOperationException('SPLIT', 'children do not consume the parent and no remainder child was requested', [
                    'remainder_gross_mg' => $remainder,
                ]);
            }

            $grossMg[] = $remainder;
            $serials[] = null;
        }

        // F1 per child. floor() means Σ children fine can only ever be <= the
        // parent's fine weight, so the loss is non-negative by construction.
        $fineMg = array_map(
            fn (int $g): int => FineWeight::calculate(Weight::fromMilligrams($g), $purity)->milligrams,
            $grossMg,
        );

        $fineMg = $this->capFineToParent($fineMg, $parentFine);

        return [
            'grossMg' => array_values($grossMg),
            'fineMg' => array_values($fineMg),
            'serials' => array_values($serials),
            'grossLossMg' => $command->physicalLossGrossMg,
            'fineLossMg' => $parentFine - (int) array_sum($fineMg),
        ];
    }

    /**
     * A parent whose fine weight is below its own F1 value — the output of a
     * MERGE, where fine is the exact sum of the inputs — can produce children
     * whose F1 values add up to more than the parent holds. Trim the surplus
     * from the largest children first so the split can never mint metal.
     *
     * @param  list<int>  $fineMg
     * @return list<int>
     */
    private function capFineToParent(array $fineMg, int $parentFine): array
    {
        $excess = (int) array_sum($fineMg) - $parentFine;

        if ($excess <= 0) {
            return $fineMg;
        }

        $order = array_keys($fineMg);
        usort($order, static fn (int $a, int $b): int => $fineMg[$b] <=> $fineMg[$a] ?: $a <=> $b);

        while ($excess > 0) {
            $progressed = false;

            foreach ($order as $index) {
                if ($excess === 0) {
                    break;
                }

                if ($fineMg[$index] > 0) {
                    $fineMg[$index]--;
                    $excess--;
                    $progressed = true;
                }
            }

            if (! $progressed) {
                throw new WeightConservationException('SPLIT', 'fine', $parentFine, (int) array_sum($fineMg), 0);
            }
        }

        return $fineMg;
    }

    private function assertConservation(SplitResult $result): void
    {
        if ($result->totalChildGrossMg() + $result->grossLossMg !== $result->parentGrossMg) {
            throw new WeightConservationException(
                'SPLIT',
                'gross',
                $result->parentGrossMg,
                $result->totalChildGrossMg(),
                $result->grossLossMg,
            );
        }

        if ($result->totalChildFineMg() + $result->fineLossMg !== $result->parentFineMg) {
            throw new WeightConservationException(
                'SPLIT',
                'fine',
                $result->parentFineMg,
                $result->totalChildFineMg(),
                $result->fineLossMg,
            );
        }

        if ($result->fineLossMg < 0) {
            throw new WeightConservationException(
                'SPLIT',
                'fine',
                $result->parentFineMg,
                $result->totalChildFineMg(),
                $result->fineLossMg,
            );
        }
    }

    /** @param list<GoldLotModel> $children */
    private function dispatchEvents(SplitResult $result, array $children, SplitLotCommand $command): void
    {
        $occurredAt = now()->toIso8601String();

        event(new LotSplit(
            parentLotId: $result->parentLotId,
            childLotIds: $result->childLotIds,
            childGrossWeightsMg: $result->childGrossMg,
            childFineWeightsMg: $result->childFineMg,
            ownerOrganizationId: $result->ownerOrganizationId,
            parentGrossMg: $result->parentGrossMg,
            parentFineMg: $result->parentFineMg,
            grossLossMg: $result->grossLossMg,
            fineLossMg: $result->fineLossMg,
            operationId: $result->operationId,
            requestedByUserId: $command->requestedByUserId,
            occurredAt: $occurredAt,
        ));

        foreach ($children as $child) {
            event(new GoldLotCreated(
                lotId: (int) $child->id,
                lotCode: (string) $child->lot_code,
                ownerOrganizationId: (int) $child->owner_organization_id,
                grossWeightMg: (int) $child->gross_weight_mg,
                purityX10: (int) $child->purity_x10,
                fineWeightMg: (int) $child->fine_weight_mg,
                puritySource: $child->purity_source->value,
                originType: $child->origin_type->value,
                status: $child->status->value,
                operationId: $result->operationId,
                createdByUserId: $command->requestedByUserId,
                occurredAt: $occurredAt,
            ));
        }
    }

    private function vaultIdOf(GoldLotModel $lot): ?int
    {
        return $lot->custodian_type->allowsBookTransfer() ? (int) $lot->custodian_id : null;
    }

    private function assertPartCount(SplitLotCommand $command): void
    {
        $max = self::DEFAULT_MAX_CHILDREN;
        $container = Container::getInstance();

        if ($container->bound('config')) {
            $max = (int) $container->make('config')->get('goldb2b.custody.split.max_children', $max);
        }

        if (count($command->parts) > $max) {
            throw new InvalidLotOperationException('SPLIT', "a split may not produce more than {$max} children", [
                'requested' => count($command->parts),
            ]);
        }
    }
}
