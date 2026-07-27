<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\DepositCommand;
use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Application\Commands\WithdrawalRequestCommand;
use App\Modules\Custody\Application\Results\DepositResult;
use App\Modules\Custody\Application\Results\WaybillIssue;
use App\Modules\Custody\Application\Results\WithdrawalResult;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\CustodyOperationStatus;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\LotNotOwnedException;
use App\Modules\Custody\Domain\Exceptions\WaybillVerificationException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Domain\ValueObjects\CodeFormat;
use App\Modules\Custody\Events\GoldLotCreated;
use App\Modules\Custody\Events\LotEnteredVault;
use App\Modules\Custody\Events\LotHeld;
use App\Modules\Custody\Events\LotLeftVault;
use App\Modules\Custody\Events\LotReleased;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\VaultBoxModel;
use App\Modules\Custody\Infrastructure\Models\VaultModel;
use App\Modules\Custody\Infrastructure\Models\VaultWaybillModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Vault IN / OUT and in-vault operations —
 * docs/03-domain/06-custody-vault.md §6.3, §6.4, §6.5, §6.8.
 *
 * The withdrawal path is deliberately four separate calls:
 *
 *   requestWithdrawal  TREASURER asks, lots go RESERVED
 *   approveWithdrawal  a different user in the org approves       (dual control)
 *   issueWaybill       a platform VAULT_OFFICER issues the permit (segregation)
 *   executeWithdrawal  the one-time code is presented at the counter
 *
 * Each step is a separate transaction because a human acts in between; the
 * lots stay RESERVED throughout, so the metal cannot be sold from under the
 * request.
 */
final readonly class VaultService
{
    public const DEFAULT_CODE_DIGITS = 8;

    public const DEFAULT_CODE_TTL_MINUTES = 120;

    public function __construct(
        private LotCreator $lots,
        private LotStateMachine $stateMachine,
        private CustodyOperationRecorder $recorder,
        private QrTokenService $qrTokens,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Vault IN
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create one lot per physical piece and place it in the vault.
     *
     * A piece that arrives without a certificate from an accredited lab is
     * booked UNDER_ASSAY (§6.3 step 6) and is not tradable until AssayService
     * records one.
     */
    public function deposit(DepositCommand $command): DepositResult
    {
        /** @var array{result: DepositResult, lots: list<GoldLotModel>} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $vault = VaultModel::query()->find($command->vaultId);

            if (! $vault instanceof VaultModel) {
                throw new CustodyEntityNotFoundException('Vault', $command->vaultId);
            }

            if (! $vault->acceptsDeposits()) {
                throw new InvalidLotOperationException('VAULT_IN', 'vault is not accepting deposits', [
                    'vault_id' => $command->vaultId,
                    'vault_status' => $vault->status,
                ]);
            }

            $created = [];
            $pendingAssay = [];

            foreach ($command->pieces as $piece) {
                $boxId = $piece->vaultBoxId ?? $command->defaultVaultBoxId;
                $box = $this->resolveBox($boxId, $command->vaultId);

                $status = $piece->puritySource === PuritySource::ASSAYED
                    ? LotStatus::AVAILABLE
                    : LotStatus::UNDER_ASSAY;

                $lot = $this->lots->create(new NewLotSpec(
                    ownerOrganizationId: $command->ownerOrganizationId,
                    gross: $piece->gross,
                    purity: $piece->purity,
                    puritySource: $piece->puritySource,
                    shape: $piece->shape,
                    originType: OriginType::MEMBER_DEPOSIT,
                    custodianType: CustodianType::VAULT,
                    custodianId: $command->vaultId,
                    status: $status,
                    vaultBoxId: $box?->id === null ? null : (int) $box->id,
                    physicalLocation: $box?->full_code,
                    serialNumber: $piece->serialNumber,
                    hallmarkCode: $piece->hallmarkCode,
                    refinerId: $piece->refinerId,
                    refinedAt: $piece->refinedAt,
                    createdByUserId: $command->executedByUserId,
                ));

                $created[] = $lot;

                if ($status === LotStatus::UNDER_ASSAY) {
                    $pendingAssay[] = (int) $lot->id;
                }
            }

            $lotIds = array_map(static fn (GoldLotModel $l): int => (int) $l->id, $created);
            $totalGross = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->gross_weight_mg, $created));
            $totalFine = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg, $created));

            $operation = $this->recorder->record(
                type: CustodyOperationType::VAULT_IN,
                inputLotIds: $lotIds,
                outputLotIds: $lotIds,
                inputFineMg: $totalFine,
                outputFineMg: $totalFine,
                lossFineMg: 0,
                requestedByUserId: $command->requestedByUserId,
                vaultId: $command->vaultId,
                organizationId: $command->ownerOrganizationId,
                toLocation: $created[0]->physical_location,
                reason: $command->reason,
                referenceType: $command->referenceType,
                referenceId: $command->referenceId,
                executedByUserId: $command->executedByUserId,
            );

            foreach ($created as $lot) {
                $this->recorder->openCustodyRecord(
                    $lot,
                    (int) $operation->id,
                    $command->executedByUserId,
                    $command->vaultId,
                );
            }

            return [
                'result' => new DepositResult(
                    operationId: (int) $operation->id,
                    vaultId: $command->vaultId,
                    ownerOrganizationId: $command->ownerOrganizationId,
                    lotIds: $lotIds,
                    lotCodes: array_map(static fn (GoldLotModel $l): string => (string) $l->lot_code, $created),
                    totalGrossMg: $totalGross,
                    totalFineMg: $totalFine,
                    pendingAssayLotIds: $pendingAssay,
                ),
                'lots' => $created,
            ];
        }, attempts: 3);

        $occurredAt = now()->toIso8601String();

        foreach ($outcome['lots'] as $lot) {
            event(new GoldLotCreated(
                lotId: (int) $lot->id,
                lotCode: (string) $lot->lot_code,
                ownerOrganizationId: (int) $lot->owner_organization_id,
                grossWeightMg: (int) $lot->gross_weight_mg,
                purityX10: (int) $lot->purity_x10,
                fineWeightMg: (int) $lot->fine_weight_mg,
                puritySource: $lot->purity_source->value,
                originType: $lot->origin_type->value,
                status: $lot->status->value,
                operationId: $outcome['result']->operationId,
                createdByUserId: $command->executedByUserId,
                occurredAt: $occurredAt,
            ));

            event(new LotEnteredVault(
                lotId: (int) $lot->id,
                lotCode: (string) $lot->lot_code,
                vaultId: $command->vaultId,
                locationCode: $lot->physical_location,
                ownerOrganizationId: (int) $lot->owner_organization_id,
                fineWeightMg: (int) $lot->fine_weight_mg,
                operationId: $outcome['result']->operationId,
                byUserId: $command->executedByUserId,
                occurredAt: $occurredAt,
            ));
        }

        return $outcome['result'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Vault OUT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Step 1-2 and 6: validate, then reserve the lots.
     *
     * Refuses outright when a lot is not AVAILABLE or belongs to someone else —
     * these are the two checks that stop a withdrawal being used to move
     * another member's metal.
     */
    public function requestWithdrawal(WithdrawalRequestCommand $command): CustodyOperationModel
    {
        return DB::transaction(function () use ($command): CustodyOperationModel {
            $lots = $this->lockLots($command->orderedLotIds());

            foreach ($lots as $lot) {
                if ((int) $lot->owner_organization_id !== $command->ownerOrganizationId) {
                    throw new LotNotOwnedException((int) $lot->id, $command->ownerOrganizationId);
                }

                if ($lot->status !== LotStatus::AVAILABLE) {
                    throw new LotNotAvailableException((int) $lot->id, $lot->status, 'VAULT_OUT');
                }

                if ($lot->custodian_type !== CustodianType::VAULT || (int) $lot->custodian_id !== $command->vaultId) {
                    throw new InvalidLotOperationException('VAULT_OUT', 'lot is not held by this vault', [
                        'lot_id' => (int) $lot->id,
                        'vault_id' => $command->vaultId,
                    ]);
                }
            }

            $totalFine = IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg, $lots));

            $operation = $this->recorder->record(
                type: CustodyOperationType::VAULT_OUT,
                inputLotIds: array_map(static fn (GoldLotModel $l): int => (int) $l->id, $lots),
                outputLotIds: [],
                inputFineMg: $totalFine,
                outputFineMg: $totalFine,
                lossFineMg: 0,
                requestedByUserId: $command->requestedByUserId,
                status: CustodyOperationStatus::REQUESTED,
                vaultId: $command->vaultId,
                organizationId: $command->ownerOrganizationId,
                fromLocation: $lots[0]->physical_location,
                reason: $command->reason,
                referenceType: $command->referenceType,
                referenceId: $command->referenceId,
                notes: $command->receiverName === null ? null : 'Receiver: '.$command->receiverName,
            );

            foreach ($lots as $lot) {
                $this->stateMachine->transition(
                    $lot,
                    LotStatus::RESERVED,
                    (new TransitionContext(
                        actorUserId: $command->requestedByUserId,
                        reason: 'Withdrawal requested',
                    ))->forOperation((int) $operation->id),
                );
            }

            return $operation;
        }, attempts: 3);
    }

    /** Step 3: a second person in the owning organization signs off. */
    public function approveWithdrawal(int $operationId, int $approverUserId): CustodyOperationModel
    {
        return DB::transaction(function () use ($operationId, $approverUserId): CustodyOperationModel {
            $operation = $this->lockOperation($operationId);

            if ($operation->status !== CustodyOperationStatus::REQUESTED) {
                throw new InvalidLotOperationException('VAULT_OUT', 'only a REQUESTED withdrawal can be approved', [
                    'operation_id' => $operationId,
                    'status' => $operation->status->value,
                ]);
            }

            if ((int) $operation->requested_by_user_id === $approverUserId) {
                throw new OperationNotPermittedException(
                    'the approver of a withdrawal must be a different user from the requester (dual control)'
                );
            }

            $operation->status = CustodyOperationStatus::APPROVED;
            $operation->approved_by_user_id = $approverUserId;
            $operation->approved_at = now();
            $operation->save();

            return $operation;
        });
    }

    /**
     * Step 7: the platform's vault officer issues the exit permit and a
     * one-time code. The officer must be neither the requester nor the
     * approver (§6.8 segregation of duties).
     */
    public function issueWaybill(
        int $operationId,
        int $vaultOfficerUserId,
        ?string $receiverName = null,
        ?string $receiverNationalIdHash = null,
    ): WaybillIssue {
        return DB::transaction(function () use ($operationId, $vaultOfficerUserId, $receiverName, $receiverNationalIdHash): WaybillIssue {
            $operation = $this->lockOperation($operationId);

            if ($operation->status !== CustodyOperationStatus::APPROVED) {
                throw new InvalidLotOperationException('VAULT_OUT', 'the withdrawal has not been approved yet', [
                    'operation_id' => $operationId,
                    'status' => $operation->status->value,
                ]);
            }

            if (
                (int) $operation->requested_by_user_id === $vaultOfficerUserId
                || (int) $operation->approved_by_user_id === $vaultOfficerUserId
            ) {
                throw new OperationNotPermittedException(
                    'the vault officer issuing a waybill may not be the requester or the approver'
                );
            }

            $code = $this->generateOneTimeCode();
            $expiresAt = now()->addMinutes($this->codeTtlMinutes());

            $waybill = new VaultWaybillModel;
            $waybill->waybill_no = 'PENDING-'.bin2hex(random_bytes(8));
            $waybill->custody_operation_id = (int) $operation->id;
            $waybill->vault_id = (int) $operation->vault_id;
            $waybill->owner_organization_id = (int) $operation->organization_id;
            $waybill->gold_lot_ids = $operation->input_lot_ids;
            $waybill->receiver_name = $receiverName;
            $waybill->receiver_national_id_hash = $receiverNationalIdHash;
            $waybill->one_time_code_hash = hash('sha256', $code);
            $waybill->code_expires_at = $expiresAt;
            $waybill->qr_token = $this->qrTokens->generate();
            $waybill->status = 'ISSUED';
            $waybill->issued_by_user_id = $vaultOfficerUserId;
            $waybill->issued_at = now();
            $waybill->save();

            $waybill->waybill_no = CodeFormat::format(
                (string) CodeFormat::setting('waybill_prefix', 'WB-'),
                (int) $waybill->id,
            );
            $waybill->save();

            return new WaybillIssue(
                waybillId: (int) $waybill->id,
                waybillNo: (string) $waybill->waybill_no,
                operationId: (int) $operation->id,
                vaultId: (int) $waybill->vault_id,
                ownerOrganizationId: (int) $waybill->owner_organization_id,
                lotIds: array_map('intval', (array) $operation->input_lot_ids),
                oneTimeCode: $code,
                expiresAt: $expiresAt->toIso8601String(),
                qrToken: (string) $waybill->qr_token,
            );
        });
    }

    /**
     * Steps 8-9: the code is presented at the counter, the metal leaves.
     *
     * RESERVED cannot go straight to WITHDRAWN in the lot state machine
     * (appendix §2.5), so the lot passes through AVAILABLE; both transitions
     * are logged against the same operation.
     */
    public function executeWithdrawal(
        int $operationId,
        string $oneTimeCode,
        int $executedByUserId,
    ): WithdrawalResult {
        /** @var array{result: WithdrawalResult, lots: list<GoldLotModel>} $outcome */
        $outcome = DB::transaction(function () use ($operationId, $oneTimeCode, $executedByUserId): array {
            $operation = $this->lockOperation($operationId);

            if ($operation->status !== CustodyOperationStatus::APPROVED) {
                throw new InvalidLotOperationException('VAULT_OUT', 'the withdrawal is not ready for execution', [
                    'operation_id' => $operationId,
                    'status' => $operation->status->value,
                ]);
            }

            $waybill = VaultWaybillModel::query()
                ->where('custody_operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            if (! $waybill instanceof VaultWaybillModel) {
                throw new CustodyEntityNotFoundException('VaultWaybill', $operationId);
            }

            $this->verifyWaybill($waybill, $oneTimeCode);

            $lotIds = array_map('intval', (array) $operation->input_lot_ids);
            sort($lotIds);
            $lots = $this->lockLots($lotIds);

            $ownerId = (int) $operation->organization_id;
            $vaultId = (int) $operation->vault_id;

            foreach ($lots as $lot) {
                if ((int) $lot->owner_organization_id !== $ownerId) {
                    throw new LotNotOwnedException((int) $lot->id, $ownerId);
                }

                if ($lot->status !== LotStatus::RESERVED) {
                    throw new LotNotAvailableException((int) $lot->id, $lot->status, 'VAULT_OUT');
                }
            }

            $operation->status = CustodyOperationStatus::EXECUTING;
            $operation->save();

            foreach ($lots as $lot) {
                $context = (new TransitionContext(
                    actorUserId: $executedByUserId,
                    reason: 'Handed over on waybill '.$waybill->waybill_no,
                ))->forOperation((int) $operation->id);

                $this->stateMachine->transition($lot, LotStatus::AVAILABLE, $context);
                $this->stateMachine->transition($lot, LotStatus::WITHDRAWN, $context);

                $this->recorder->closeCustodyRecord((int) $lot->id, (int) $operation->id, $executedByUserId);

                $lot->custodian_type = CustodianType::ORGANIZATION;
                $lot->custodian_id = $ownerId;
                $lot->vault_box_id = null;
                $lot->physical_location = null;
                $lot->version = (int) $lot->version + 1;
                $lot->save();

                $this->recorder->openCustodyRecord($lot, (int) $operation->id, $executedByUserId);
            }

            $waybill->status = 'USED';
            $waybill->code_used_at = now();
            $waybill->save();

            $operation->status = CustodyOperationStatus::COMPLETED;
            $operation->executed_by_user_id = $executedByUserId;
            $operation->executed_at = now();
            $operation->save();

            return [
                'result' => new WithdrawalResult(
                    operationId: (int) $operation->id,
                    waybillNo: (string) $waybill->waybill_no,
                    vaultId: $vaultId,
                    ownerOrganizationId: $ownerId,
                    lotIds: array_map(static fn (GoldLotModel $l): int => (int) $l->id, $lots),
                    lotCodes: array_map(static fn (GoldLotModel $l): string => (string) $l->lot_code, $lots),
                    totalFineMg: IntMath::sum(array_map(static fn (GoldLotModel $l): int => (int) $l->fine_weight_mg, $lots)),
                ),
                'lots' => $lots,
            ];
        }, attempts: 3);

        $occurredAt = now()->toIso8601String();

        foreach ($outcome['lots'] as $lot) {
            event(new LotLeftVault(
                lotId: (int) $lot->id,
                lotCode: (string) $lot->lot_code,
                vaultId: $outcome['result']->vaultId,
                receiverOrganizationId: $outcome['result']->ownerOrganizationId,
                waybillNo: $outcome['result']->waybillNo,
                fineWeightMg: (int) $lot->fine_weight_mg,
                operationId: $outcome['result']->operationId,
                byUserId: $executedByUserId,
                occurredAt: $occurredAt,
            ));
        }

        return $outcome['result'];
    }

    /** Cancels a pending withdrawal and releases the reservation. */
    public function cancelWithdrawal(int $operationId, int $byUserId, string $reason): CustodyOperationModel
    {
        return DB::transaction(function () use ($operationId, $byUserId, $reason): CustodyOperationModel {
            $operation = $this->lockOperation($operationId);

            if ($operation->status->isFinal()) {
                throw new InvalidLotOperationException('VAULT_OUT', 'the withdrawal is already closed', [
                    'operation_id' => $operationId,
                    'status' => $operation->status->value,
                ]);
            }

            $lotIds = array_map('intval', (array) $operation->input_lot_ids);
            sort($lotIds);

            foreach ($this->lockLots($lotIds) as $lot) {
                if ($lot->status === LotStatus::RESERVED) {
                    $this->stateMachine->transition(
                        $lot,
                        LotStatus::AVAILABLE,
                        (new TransitionContext(actorUserId: $byUserId, reason: $reason))
                            ->forOperation((int) $operation->id),
                    );
                }
            }

            VaultWaybillModel::query()
                ->where('custody_operation_id', $operationId)
                ->where('status', 'ISSUED')
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);

            $operation->status = CustodyOperationStatus::CANCELLED;
            $operation->notes = $reason;
            $operation->save();

            return $operation;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // In-vault operations
    // ─────────────────────────────────────────────────────────────────────

    /** Move a lot to another box inside the same vault (§6.5 RELOCATE). */
    public function relocate(int $lotId, int $toVaultBoxId, int $byUserId, ?string $reason = null): GoldLotModel
    {
        return DB::transaction(function () use ($lotId, $toVaultBoxId, $byUserId, $reason): GoldLotModel {
            $lot = $this->lockLot($lotId);

            if ($lot->custodian_type !== CustodianType::VAULT) {
                throw new InvalidLotOperationException('RELOCATE', 'only vault-held lots can be relocated', [
                    'lot_id' => $lotId,
                    'custodian_type' => $lot->custodian_type->value,
                ]);
            }

            $box = $this->resolveBox($toVaultBoxId, (int) $lot->custodian_id);

            if ($box === null) {
                throw new CustodyEntityNotFoundException('VaultBox', $toVaultBoxId);
            }

            $from = $lot->physical_location;

            $operation = $this->recorder->record(
                type: CustodyOperationType::RELOCATE,
                inputLotIds: [$lotId],
                outputLotIds: [$lotId],
                inputFineMg: (int) $lot->fine_weight_mg,
                outputFineMg: (int) $lot->fine_weight_mg,
                lossFineMg: 0,
                requestedByUserId: $byUserId,
                vaultId: (int) $lot->custodian_id,
                organizationId: (int) $lot->owner_organization_id,
                fromLocation: $from,
                toLocation: $box->full_code,
                reason: $reason,
                executedByUserId: $byUserId,
            );

            $this->recorder->closeCustodyRecord($lotId, (int) $operation->id, $byUserId);

            $lot->vault_box_id = (int) $box->id;
            $lot->physical_location = $box->full_code;
            $lot->version = (int) $lot->version + 1;
            $lot->save();

            $this->recorder->openCustodyRecord($lot, (int) $operation->id, $byUserId, (int) $lot->custodian_id);

            return $lot;
        }, attempts: 3);
    }

    /** Administrative hold — dispute, AML flag, court order (§6.5 HOLD). */
    public function hold(
        int $lotId,
        string $reason,
        int $byUserId,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): GoldLotModel {
        /** @var array{lot: GoldLotModel, previous: string} $outcome */
        $outcome = DB::transaction(function () use ($lotId, $reason, $byUserId, $referenceType, $referenceId): array {
            $lot = $this->lockLot($lotId);
            $previous = $lot->status;

            $operation = $this->recorder->record(
                type: CustodyOperationType::HOLD,
                inputLotIds: [$lotId],
                outputLotIds: [$lotId],
                inputFineMg: (int) $lot->fine_weight_mg,
                outputFineMg: (int) $lot->fine_weight_mg,
                lossFineMg: 0,
                requestedByUserId: $byUserId,
                vaultId: $lot->custodian_type === CustodianType::VAULT ? (int) $lot->custodian_id : null,
                organizationId: (int) $lot->owner_organization_id,
                reason: $reason,
                referenceType: $referenceType,
                referenceId: $referenceId,
                executedByUserId: $byUserId,
            );

            $this->stateMachine->transition(
                $lot,
                LotStatus::ON_HOLD,
                new TransitionContext(
                    actorUserId: $byUserId,
                    reason: $reason,
                    referenceType: $referenceType ?? 'custody_operation',
                    referenceId: $referenceId ?? (int) $operation->id,
                ),
            );

            return ['lot' => $lot, 'previous' => $previous->value];
        }, attempts: 3);

        $lot = $outcome['lot'];

        event(new LotHeld(
            lotId: (int) $lot->id,
            lotCode: (string) $lot->lot_code,
            ownerOrganizationId: (int) $lot->owner_organization_id,
            reason: $reason,
            previousStatus: $outcome['previous'],
            referenceType: $referenceType,
            referenceId: $referenceId,
            byUserId: $byUserId,
            occurredAt: now()->toIso8601String(),
        ));

        return $lot;
    }

    /** Lift an administrative hold (§6.5 RELEASE). */
    public function release(int $lotId, int $byUserId, ?string $reason = null): GoldLotModel
    {
        $lot = DB::transaction(function () use ($lotId, $byUserId, $reason): GoldLotModel {
            $lot = $this->lockLot($lotId);

            $operation = $this->recorder->record(
                type: CustodyOperationType::RELEASE,
                inputLotIds: [$lotId],
                outputLotIds: [$lotId],
                inputFineMg: (int) $lot->fine_weight_mg,
                outputFineMg: (int) $lot->fine_weight_mg,
                lossFineMg: 0,
                requestedByUserId: $byUserId,
                vaultId: $lot->custodian_type === CustodianType::VAULT ? (int) $lot->custodian_id : null,
                organizationId: (int) $lot->owner_organization_id,
                reason: $reason,
                executedByUserId: $byUserId,
            );

            $this->stateMachine->transition(
                $lot,
                LotStatus::AVAILABLE,
                (new TransitionContext(actorUserId: $byUserId, reason: $reason ?? 'Hold released'))
                    ->forOperation((int) $operation->id),
            );

            return $lot;
        }, attempts: 3);

        event(new LotReleased(
            lotId: (int) $lot->id,
            lotCode: (string) $lot->lot_code,
            ownerOrganizationId: (int) $lot->owner_organization_id,
            reason: $reason,
            byUserId: $byUserId,
            occurredAt: now()->toIso8601String(),
        ));

        return $lot;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private function verifyWaybill(VaultWaybillModel $waybill, string $code): void
    {
        $no = (string) $waybill->waybill_no;

        if ($waybill->status === 'CANCELLED') {
            throw new WaybillVerificationException($no, WaybillVerificationException::REASON_CANCELLED);
        }

        if ($waybill->status === 'USED' || $waybill->code_used_at !== null) {
            throw new WaybillVerificationException($no, WaybillVerificationException::REASON_ALREADY_USED);
        }

        if ($waybill->code_expires_at !== null && $waybill->code_expires_at->isPast()) {
            $waybill->status = 'EXPIRED';
            $waybill->save();

            throw new WaybillVerificationException($no, WaybillVerificationException::REASON_EXPIRED);
        }

        if (! hash_equals((string) $waybill->one_time_code_hash, hash('sha256', $code))) {
            $waybill->code_attempts = (int) $waybill->code_attempts + 1;
            $waybill->save();

            throw new WaybillVerificationException($no, WaybillVerificationException::REASON_BAD_CODE);
        }
    }

    private function lockOperation(int $operationId): CustodyOperationModel
    {
        $operation = CustodyOperationModel::query()
            ->whereKey($operationId)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof CustodyOperationModel) {
            throw new CustodyEntityNotFoundException('CustodyOperation', $operationId);
        }

        return $operation;
    }

    private function lockLot(int $lotId): GoldLotModel
    {
        $lot = GoldLotModel::query()->whereKey($lotId)->lockForUpdate()->first();

        if (! $lot instanceof GoldLotModel) {
            throw new CustodyEntityNotFoundException('GoldLot', $lotId);
        }

        return $lot;
    }

    /**
     * @param  list<int>  $ids  must already be sorted ascending
     * @return list<GoldLotModel>
     */
    private function lockLots(array $ids): array
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

    private function resolveBox(?int $boxId, int $vaultId): ?VaultBoxModel
    {
        if ($boxId === null) {
            return null;
        }

        $box = VaultBoxModel::query()->find($boxId);

        if (! $box instanceof VaultBoxModel) {
            throw new CustodyEntityNotFoundException('VaultBox', $boxId);
        }

        if ((int) $box->vault_id !== $vaultId) {
            throw new InvalidLotOperationException('VAULT', 'box does not belong to this vault', [
                'box_id' => $boxId,
                'vault_id' => $vaultId,
            ]);
        }

        return $box;
    }

    private function generateOneTimeCode(): string
    {
        $digits = $this->codeDigits();
        $code = '';

        for ($i = 0; $i < $digits; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    private function codeDigits(): int
    {
        return (int) $this->setting('code_digits', self::DEFAULT_CODE_DIGITS);
    }

    private function codeTtlMinutes(): int
    {
        return (int) $this->setting('code_ttl_minutes', self::DEFAULT_CODE_TTL_MINUTES);
    }

    private function setting(string $key, int $default): int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return $default;
        }

        return (int) $container->make('config')->get("goldb2b.custody.withdrawal.{$key}", $default);
    }
}
