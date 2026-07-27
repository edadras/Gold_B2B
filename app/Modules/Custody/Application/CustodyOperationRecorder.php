<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Domain\Enums\CustodyOperationStatus;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Enums\LineageOperation;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\CustodyRecordModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LotLineageModel;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Container\Container;

/**
 * Writes the audit spine of the module: custody_operations, lot_lineage and
 * custody_records. docs/03-domain/06-custody-vault.md §6.5, §2.7.
 *
 * Always called from inside the calling service's transaction.
 */
final class CustodyOperationRecorder
{
    public const DEFAULT_EXTRA_APPROVAL_BPS = 50;   // 0.5%

    /**
     * @param  list<int>  $inputLotIds
     * @param  list<int>  $outputLotIds
     */
    public function record(
        CustodyOperationType $type,
        array $inputLotIds,
        array $outputLotIds,
        int $inputFineMg,
        int $outputFineMg,
        int $lossFineMg,
        int $requestedByUserId,
        CustodyOperationStatus $status = CustodyOperationStatus::COMPLETED,
        ?int $vaultId = null,
        ?int $organizationId = null,
        int $lossGrossMg = 0,
        ?string $fromLocation = null,
        ?string $toLocation = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $approvedByUserId = null,
        ?int $executedByUserId = null,
        ?string $notes = null,
    ): CustodyOperationModel {
        $now = now();

        return CustodyOperationModel::query()->create([
            'operation_type' => $type->value,
            'vault_id' => $vaultId,
            'organization_id' => $organizationId,
            'input_lot_ids' => array_values($inputLotIds),
            'output_lot_ids' => $outputLotIds === [] ? null : array_values($outputLotIds),
            'input_fine_mg' => $inputFineMg,
            'output_fine_mg' => $outputFineMg,
            'loss_fine_mg' => $lossFineMg,
            'loss_gross_mg' => $lossGrossMg,
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'requested_by_user_id' => $requestedByUserId,
            'approved_by_user_id' => $approvedByUserId,
            'executed_by_user_id' => $executedByUserId,
            'status' => $status->value,
            'requires_extra_approval' => $this->needsExtraApproval($inputFineMg, $lossFineMg),
            'requested_at' => $now,
            'approved_at' => $approvedByUserId === null ? null : $now,
            'executed_at' => $status === CustodyOperationStatus::COMPLETED ? $now : null,
            'notes' => $notes,
        ]);
    }

    /**
     * Loss above the configured share of the input needs a second approver —
     * docs/03-domain/06-custody-vault.md §6.5.
     */
    public function needsExtraApproval(int $inputFineMg, int $lossFineMg): bool
    {
        if ($lossFineMg <= 0 || $inputFineMg <= 0) {
            return false;
        }

        $lossBps = IntMath::mulDivFloor($lossFineMg, 10_000, $inputFineMg);

        return $lossBps > $this->extraApprovalBps();
    }

    public function linkLineage(
        int $parentLotId,
        int $childLotId,
        LineageOperation $operation,
        int $operationId,
    ): LotLineageModel {
        return LotLineageModel::query()->create([
            'parent_lot_id' => $parentLotId,
            'child_lot_id' => $childLotId,
            'operation' => $operation->value,
            'operation_id' => $operationId,
            'created_at' => now(),
        ]);
    }

    /** Opens the ACTIVE custody row for a lot at its current location. */
    public function openCustodyRecord(
        GoldLotModel $lot,
        ?int $operationId = null,
        ?int $byUserId = null,
        ?int $vaultId = null,
        ?string $notes = null,
    ): CustodyRecordModel {
        return CustodyRecordModel::query()->create([
            'gold_lot_id' => $lot->id,
            'custodian_type' => $lot->custodian_type->value,
            'custodian_id' => $lot->custodian_id,
            'vault_id' => $vaultId,
            'vault_box_id' => $lot->vault_box_id,
            'physical_location' => $lot->physical_location,
            'gross_weight_mg' => $lot->gross_weight_mg,
            'fine_weight_mg' => $lot->fine_weight_mg,
            'received_at' => now(),
            'received_operation_id' => $operationId,
            'received_by_user_id' => $byUserId,
            'status' => 'ACTIVE',
            'notes' => $notes,
        ]);
    }

    /** Closes any ACTIVE custody row for a lot. Safe to call when none exists. */
    public function closeCustodyRecord(
        int $lotId,
        ?int $operationId = null,
        ?int $byUserId = null,
    ): void {
        CustodyRecordModel::query()
            ->where('gold_lot_id', $lotId)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'CLOSED',
                'released_at' => now(),
                'released_operation_id' => $operationId,
                'released_by_user_id' => $byUserId,
                'updated_at' => now(),
            ]);
    }

    private function extraApprovalBps(): int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return self::DEFAULT_EXTRA_APPROVAL_BPS;
        }

        return (int) $container->make('config')
            ->get('goldb2b.custody.loss.extra_approval_bps', self::DEFAULT_EXTRA_APPROVAL_BPS);
    }
}
