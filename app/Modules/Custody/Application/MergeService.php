<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\MergeLotsCommand;
use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Application\Results\MergeResult;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Enums\LineageOperation;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\IncompatibleLotsException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\MixedPurityMergeException;
use App\Modules\Custody\Domain\Exceptions\WeightConservationException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Events\GoldLotCreated;
use App\Modules\Custody\Events\LotsMerged;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Support\Facades\DB;

/**
 * Logical merge — docs/03-domain/02-gold-lot-assay.md §2.6 case (a).
 *
 * Only lots that already share a purity can be combined without melting;
 * anything else is a MELT followed by a fresh assay. The merged lot's fine
 * weight is the exact sum of its inputs (not a fresh F1 computation) so the
 * operation is provably lossless and cannot mint metal through rounding.
 */
final readonly class MergeService
{
    public function __construct(
        private LotCreator $lots,
        private LotStateMachine $stateMachine,
        private CustodyOperationRecorder $recorder,
    ) {}

    public function merge(MergeLotsCommand $command): MergeResult
    {
        /** @var array{result: MergeResult, lot: GoldLotModel} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $inputs = $this->lockInputs($command->orderedLotIds());

            $this->assertMergeable($inputs);

            $first = $inputs[0];
            $totalGross = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->gross_weight_mg, $inputs));
            $totalFine = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg, $inputs));

            $puritySource = array_reduce(
                $inputs,
                static fn (?PuritySource $carry, GoldLotModel $l): PuritySource => $carry === null
                    ? $l->purity_source
                    : $carry->lowerOf($l->purity_source),
                null,
            );

            $generation = max(array_map(static fn (GoldLotModel $l): int => (int) $l->generation, $inputs)) + 1;

            $merged = $this->lots->create(new NewLotSpec(
                ownerOrganizationId: (int) $first->owner_organization_id,
                gross: Weight::fromMilligrams($totalGross),
                purity: $first->purity(),
                puritySource: $puritySource ?? PuritySource::DECLARED,
                shape: $command->outputShape ?? $first->shape,
                originType: OriginType::MERGE,
                custodianType: $first->custodian_type,
                custodianId: (int) $first->custodian_id,
                status: LotStatus::AVAILABLE,
                // Exact sum, never a recomputed F1 value.
                fine: FineWeight::fromMilligrams($totalFine),
                vaultBoxId: $first->vault_box_id === null ? null : (int) $first->vault_box_id,
                physicalLocation: $first->physical_location,
                serialNumber: $command->serialNumber,
                hallmarkCode: $first->hallmark_code,
                refinerId: $first->refiner_id === null ? null : (int) $first->refiner_id,
                // The merged piece is covered by no single certificate; the
                // lineage rows point at the inputs that do have one.
                currentAssayId: null,
                generation: $generation,
                metalType: $first->metal_type,
                createdByUserId: $command->requestedByUserId,
            ));

            if ((int) $merged->fine_weight_mg !== $totalFine) {
                throw new WeightConservationException('MERGE', 'fine', $totalFine, (int) $merged->fine_weight_mg, 0);
            }

            $inputIds = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $inputs);

            $operation = $this->recorder->record(
                type: CustodyOperationType::MERGE,
                inputLotIds: $inputIds,
                outputLotIds: [(int) $merged->id],
                inputFineMg: $totalFine,
                outputFineMg: $totalFine,
                lossFineMg: 0,
                requestedByUserId: $command->requestedByUserId,
                organizationId: (int) $first->owner_organization_id,
                fromLocation: $first->physical_location,
                toLocation: $merged->physical_location,
                reason: $command->reason,
                referenceType: $command->referenceType,
                referenceId: $command->referenceId,
                executedByUserId: $command->requestedByUserId,
            );

            foreach ($inputs as $input) {
                $this->recorder->linkLineage(
                    parentLotId: (int) $input->id,
                    childLotId: (int) $merged->id,
                    operation: LineageOperation::MERGE,
                    operationId: (int) $operation->id,
                );

                $this->recorder->closeCustodyRecord(
                    (int) $input->id,
                    (int) $operation->id,
                    $command->requestedByUserId,
                );

                $this->stateMachine->transition(
                    $input,
                    LotStatus::CONSUMED,
                    (new TransitionContext(
                        actorUserId: $command->requestedByUserId,
                        reason: $command->reason ?? 'Merged into lot '.$merged->lot_code,
                    ))->forOperation((int) $operation->id),
                );
            }

            $this->recorder->openCustodyRecord(
                $merged,
                (int) $operation->id,
                $command->requestedByUserId,
                $merged->custodian_type->allowsBookTransfer() ? (int) $merged->custodian_id : null,
            );

            return [
                'result' => new MergeResult(
                    inputLotIds: $inputIds,
                    newLotId: (int) $merged->id,
                    newLotCode: (string) $merged->lot_code,
                    ownerOrganizationId: (int) $merged->owner_organization_id,
                    purityX10: (int) $merged->purity_x10,
                    grossWeightMg: (int) $merged->gross_weight_mg,
                    fineWeightMg: (int) $merged->fine_weight_mg,
                    inputFineMg: $totalFine,
                    operationId: (int) $operation->id,
                ),
                'lot' => $merged,
            ];
        }, attempts: 3);

        $this->dispatchEvents($outcome['result'], $outcome['lot'], $command);

        return $outcome['result'];
    }

    /**
     * @param  list<int>  $ids
     * @return list<GoldLotModel>
     */
    private function lockInputs(array $ids): array
    {
        /** @var list<GoldLotModel> $lots */
        $lots = GoldLotModel::query()
            ->whereIn('id', $ids)
            ->orderBy('id')          // ascending lock order, AGENT_BRIEF rule 4
            ->lockForUpdate()
            ->get()
            ->all();

        if (count($lots) !== count($ids)) {
            $found = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $lots);
            $missing = array_values(array_diff($ids, $found));

            throw new CustodyEntityNotFoundException('GoldLot', $missing[0] ?? 0);
        }

        return $lots;
    }

    /** @param list<GoldLotModel> $inputs */
    private function assertMergeable(array $inputs): void
    {
        $ids = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $inputs);

        $purities = array_values(array_unique(array_map(
            static fn (GoldLotModel $l): int => (int) $l->purity_x10,
            $inputs,
        )));

        if (count($purities) > 1) {
            sort($purities);

            throw new MixedPurityMergeException($ids, $purities);
        }

        $owners = array_unique(array_map(static fn (GoldLotModel $l): int => (int) $l->owner_organization_id, $inputs));
        if (count($owners) > 1) {
            throw new IncompatibleLotsException($ids, 'owner_organization_id', 'all inputs must belong to one organization');
        }

        $metals = array_unique(array_map(static fn (GoldLotModel $l): string => $l->metal_type->value, $inputs));
        if (count($metals) > 1) {
            throw new IncompatibleLotsException($ids, 'metal_type', 'cannot merge different metals');
        }

        $custodians = array_unique(array_map(
            static fn (GoldLotModel $l): string => $l->custodian_type->value.':'.$l->custodian_id,
            $inputs,
        ));
        if (count($custodians) > 1) {
            throw new IncompatibleLotsException($ids, 'custodian', 'all inputs must be held by the same custodian');
        }

        foreach ($inputs as $input) {
            if (! $input->status->canTransitionTo(LotStatus::CONSUMED)) {
                throw new LotNotAvailableException((int) $input->id, $input->status, 'MERGE');
            }
        }
    }

    private function dispatchEvents(MergeResult $result, GoldLotModel $merged, MergeLotsCommand $command): void
    {
        $occurredAt = now()->toIso8601String();

        event(new LotsMerged(
            parentLotIds: $result->inputLotIds,
            newLotId: $result->newLotId,
            newLotCode: $result->newLotCode,
            ownerOrganizationId: $result->ownerOrganizationId,
            purityX10: $result->purityX10,
            grossWeightMg: $result->grossWeightMg,
            fineWeightMg: $result->fineWeightMg,
            operationId: $result->operationId,
            requestedByUserId: $command->requestedByUserId,
            occurredAt: $occurredAt,
        ));

        event(new GoldLotCreated(
            lotId: $result->newLotId,
            lotCode: $result->newLotCode,
            ownerOrganizationId: $result->ownerOrganizationId,
            grossWeightMg: $result->grossWeightMg,
            purityX10: $result->purityX10,
            fineWeightMg: $result->fineWeightMg,
            puritySource: $merged->purity_source->value,
            originType: $merged->origin_type->value,
            status: $merged->status->value,
            operationId: $result->operationId,
            createdByUserId: $command->requestedByUserId,
            occurredAt: $occurredAt,
        ));
    }
}
