<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\MeltLotsCommand;
use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Application\Results\MeltResult;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Enums\LineageOperation;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\IncompatibleLotsException;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\WeightConservationException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Events\GoldLotCreated;
use App\Modules\Custody\Events\LotMelted;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Facades\DB;

/**
 * Real melting — docs/03-domain/02-gold-lot-assay.md §2.6 case (b).
 *
 * Hard rule implemented here: the output of a MELT always starts with
 * purity_source = DECLARED and status = UNDER_ASSAY, so it is untradable
 * until a laboratory certificate arrives.
 */
final readonly class MeltService
{
    public function __construct(
        private LotCreator $lots,
        private LotStateMachine $stateMachine,
        private CustodyOperationRecorder $recorder,
    ) {}

    public function melt(MeltLotsCommand $command): MeltResult
    {
        /** @var array{result: MeltResult, outputs: list<GoldLotModel>} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $inputs = $this->lockInputs($command->orderedLotIds());
            $this->assertMeltable($inputs);

            $first = $inputs[0];
            $inputGross = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->gross_weight_mg, $inputs));
            $inputFine = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg, $inputs));

            $outputGross = IntMath::sum(array_map(
                static fn ($o): int => $o->gross->milligrams,
                $command->outputs,
            ));

            if ($outputGross > $inputGross) {
                throw new InvalidLotOperationException('MELT', 'output gross weight exceeds the input', [
                    'input_gross_mg' => $inputGross,
                    'output_gross_mg' => $outputGross,
                ]);
            }

            $custodianType = $command->outputCustodianType ?? $first->custodian_type;
            $custodianId = $command->outputCustodianId ?? (int) $first->custodian_id;
            $generation = max(array_map(static fn (GoldLotModel $l): int => (int) $l->generation, $inputs)) + 1;

            $outputs = [];
            foreach ($command->outputs as $spec) {
                $outputs[] = $this->lots->create(new NewLotSpec(
                    ownerOrganizationId: (int) $first->owner_organization_id,
                    gross: $spec->gross,
                    purity: $spec->declaredPurity,
                    // Hard rule — never ASSAYED straight out of the furnace.
                    puritySource: PuritySource::DECLARED,
                    shape: $spec->shape,
                    originType: OriginType::MELT,
                    custodianType: $custodianType,
                    custodianId: $custodianId,
                    status: LotStatus::UNDER_ASSAY,
                    vaultBoxId: $custodianType === $first->custodian_type && $custodianId === (int) $first->custodian_id
                        ? ($first->vault_box_id === null ? null : (int) $first->vault_box_id)
                        : null,
                    physicalLocation: $custodianType === $first->custodian_type && $custodianId === (int) $first->custodian_id
                        ? $first->physical_location
                        : null,
                    serialNumber: $spec->serialNumber,
                    hallmarkCode: $spec->hallmarkCode,
                    refinerId: $command->refinerId,
                    refinedAt: now()->toDateTimeString(),
                    currentAssayId: null,
                    generation: $generation,
                    metalType: $first->metal_type,
                    createdByUserId: $command->requestedByUserId,
                ));
            }

            $outputFine = IntMath::sum(array_map(
                static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg,
                $outputs,
            ));

            $fineLoss = IntMath::sub($inputFine, $outputFine);

            if ($fineLoss < 0) {
                throw new WeightConservationException('MELT', 'fine', $inputFine, $outputFine, $fineLoss);
            }

            $inputIds = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $inputs);
            $outputIds = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $outputs);

            $operation = $this->recorder->record(
                type: CustodyOperationType::MELT,
                inputLotIds: $inputIds,
                outputLotIds: $outputIds,
                inputFineMg: $inputFine,
                outputFineMg: $outputFine,
                lossFineMg: $fineLoss,
                requestedByUserId: $command->requestedByUserId,
                organizationId: (int) $first->owner_organization_id,
                lossGrossMg: IntMath::sub($inputGross, $outputGross),
                fromLocation: $first->physical_location,
                toLocation: $outputs[0]->physical_location,
                reason: $command->reason,
                referenceType: $command->referenceType,
                referenceId: $command->referenceId,
                executedByUserId: $command->requestedByUserId,
            );

            foreach ($inputs as $input) {
                foreach ($outputIds as $outputId) {
                    $this->recorder->linkLineage(
                        parentLotId: (int) $input->id,
                        childLotId: $outputId,
                        operation: LineageOperation::MELT,
                        operationId: (int) $operation->id,
                    );
                }

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
                        reason: $command->reason ?? 'Melted',
                    ))->forOperation((int) $operation->id),
                );
            }

            foreach ($outputs as $output) {
                $this->recorder->openCustodyRecord(
                    $output,
                    (int) $operation->id,
                    $command->requestedByUserId,
                    $output->custodian_type->allowsBookTransfer() ? (int) $output->custodian_id : null,
                );
            }

            return [
                'result' => new MeltResult(
                    inputLotIds: $inputIds,
                    outputLotIds: $outputIds,
                    outputLotCodes: array_map(static fn (GoldLotModel $l): string => (string) $l->lot_code, $outputs),
                    ownerOrganizationId: (int) $first->owner_organization_id,
                    refinerId: $command->refinerId,
                    inputGrossMg: $inputGross,
                    outputGrossMg: $outputGross,
                    inputFineMg: $inputFine,
                    outputFineMg: $outputFine,
                    grossLossMg: IntMath::sub($inputGross, $outputGross),
                    fineLossMg: $fineLoss,
                    operationId: (int) $operation->id,
                ),
                'outputs' => $outputs,
            ];
        }, attempts: 3);

        $this->dispatchEvents($outcome['result'], $outcome['outputs'], $command);

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
            ->orderBy('id')
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
    private function assertMeltable(array $inputs): void
    {
        $ids = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $inputs);

        $owners = array_unique(array_map(static fn (GoldLotModel $l): int => (int) $l->owner_organization_id, $inputs));
        if (count($owners) > 1) {
            throw new IncompatibleLotsException($ids, 'owner_organization_id', 'cannot melt gold belonging to several members in one crucible');
        }

        $metals = array_unique(array_map(static fn (GoldLotModel $l): string => $l->metal_type->value, $inputs));
        if (count($metals) > 1) {
            throw new IncompatibleLotsException($ids, 'metal_type', 'cannot melt different metals together');
        }

        foreach ($inputs as $input) {
            if (! $input->status->canTransitionTo(LotStatus::CONSUMED)) {
                throw new LotNotAvailableException((int) $input->id, $input->status, 'MELT');
            }
        }
    }

    /** @param list<GoldLotModel> $outputs */
    private function dispatchEvents(MeltResult $result, array $outputs, MeltLotsCommand $command): void
    {
        $occurredAt = now()->toIso8601String();

        event(new LotMelted(
            inputLotIds: $result->inputLotIds,
            outputLotIds: $result->outputLotIds,
            ownerOrganizationId: $result->ownerOrganizationId,
            refinerId: $result->refinerId,
            inputFineMg: $result->inputFineMg,
            outputFineMg: $result->outputFineMg,
            lossFineMg: $result->fineLossMg,
            operationId: $result->operationId,
            requestedByUserId: $command->requestedByUserId,
            occurredAt: $occurredAt,
        ));

        foreach ($outputs as $output) {
            event(new GoldLotCreated(
                lotId: (int) $output->id,
                lotCode: (string) $output->lot_code,
                ownerOrganizationId: (int) $output->owner_organization_id,
                grossWeightMg: (int) $output->gross_weight_mg,
                purityX10: (int) $output->purity_x10,
                fineWeightMg: (int) $output->fine_weight_mg,
                puritySource: $output->purity_source->value,
                originType: $output->origin_type->value,
                status: $output->status->value,
                operationId: $result->operationId,
                createdByUserId: $command->requestedByUserId,
                occurredAt: $occurredAt,
            ));
        }
    }
}
