<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\RecordAssayCommand;
use App\Modules\Custody\Application\Results\AssayResult;
use App\Modules\Custody\Domain\Enums\AssayStatus;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Domain\ValueObjects\AssayCode;
use App\Modules\Custody\Events\AssayAdjusted;
use App\Modules\Custody\Events\AssayRecorded;
use App\Modules\Custody\Infrastructure\Models\AssayModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LaboratoryModel;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use Illuminate\Support\Facades\DB;

/**
 * Records assay certificates — docs/03-domain/02-gold-lot-assay.md §2.4.
 *
 * A re-assay never edits the old certificate: it writes a new VALID row and
 * flips the old one to SUPERSEDED. The certificate is authoritative for gross
 * weight, purity and fine weight, so the lot is restated from it and the signed
 * difference is published on AssayAdjusted for the Ledger to post.
 *
 * An UNACCREDITED laboratory can only ever produce DECLARED purity (§2.9).
 */
final readonly class AssayService
{
    public function __construct(
        private LotStateMachine $stateMachine,
        private QrTokenService $qrTokens,
    ) {}

    public function record(RecordAssayCommand $command): AssayResult
    {
        /** @var array{result: AssayResult, assay: AssayModel, lab: LaboratoryModel} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $lot = GoldLotModel::query()
                ->whereKey($command->goldLotId)
                ->lockForUpdate()
                ->first();

            if (! $lot instanceof GoldLotModel) {
                throw new CustodyEntityNotFoundException('GoldLot', $command->goldLotId);
            }

            if ($lot->status === LotStatus::CONSUMED) {
                throw new LotNotAvailableException((int) $lot->id, $lot->status, 'ASSAY');
            }

            $laboratory = LaboratoryModel::query()->find($command->laboratoryId);

            if (! $laboratory instanceof LaboratoryModel) {
                throw new CustodyEntityNotFoundException('Laboratory', $command->laboratoryId);
            }

            $previous = AssayModel::query()
                ->where('gold_lot_id', $lot->id)
                ->where('status', AssayStatus::VALID->value)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $newFine = FineWeight::calculate($command->grossWeight, $command->purity);

            $assay = new AssayModel;
            $assay->assay_code = AssayCode::placeholder();
            $assay->gold_lot_id = (int) $lot->id;
            $assay->certificate_no = $command->certificateNo;
            $assay->laboratory_id = $command->laboratoryId;
            $assay->method = $command->method;
            $assay->gross_weight_mg = $command->grossWeight->milligrams;
            $assay->purity_x10 = $command->purity->value;
            $assay->fine_weight_mg = $newFine->milligrams;
            $assay->assayed_at = $command->assayedAt;
            $assay->valid_until = $command->validUntil;
            $assay->document_id = $command->documentId;
            $assay->qr_token = $this->qrTokens->generate();
            $assay->status = AssayStatus::VALID;
            $assay->verified_by_lab_at = $command->verifiedByLabAt;
            $assay->recorded_by_user_id = $command->recordedByUserId;
            $assay->created_at = now();
            $assay->save();

            $assay->assay_code = AssayCode::forSequence((int) $assay->id)->value;
            $assay->save();

            if ($previous instanceof AssayModel) {
                // Never deleted, never rewritten — only superseded.
                $previous->status = AssayStatus::SUPERSEDED;
                $previous->superseded_by_id = (int) $assay->id;
                $previous->save();
            }

            $previousFine = (int) $lot->fine_weight_mg;
            $previousPurity = (int) $lot->purity_x10;
            $ownerId = (int) $lot->owner_organization_id;

            $lot->gross_weight_mg = $command->grossWeight->milligrams;
            $lot->purity_x10 = $command->purity->value;
            $lot->fine_weight_mg = $newFine->milligrams;
            $lot->purity_source = $laboratory->accreditation_level->grantedPuritySource();
            $lot->current_assay_id = (int) $assay->id;
            $lot->version = (int) $lot->version + 1;
            $lot->save();

            if ($lot->status === LotStatus::UNDER_ASSAY) {
                $this->stateMachine->transition(
                    $lot,
                    LotStatus::AVAILABLE,
                    new TransitionContext(
                        actorUserId: $command->recordedByUserId,
                        reason: $command->reason ?? 'Certificate '.$assay->assay_code.' issued',
                        referenceType: 'assay',
                        referenceId: (int) $assay->id,
                    ),
                );
            }

            $result = new AssayResult(
                assayId: (int) $assay->id,
                assayCode: (string) $assay->assay_code,
                goldLotId: (int) $lot->id,
                ownerOrganizationId: $ownerId,
                previousAssayId: $previous instanceof AssayModel ? (int) $previous->id : null,
                previousAssayCode: $previous instanceof AssayModel ? (string) $previous->assay_code : null,
                previousPurityX10: $previousPurity,
                newPurityX10: $command->purity->value,
                previousFineMg: $previousFine,
                newFineMg: $newFine->milligrams,
                fineDeltaMg: IntMath::sub($newFine->milligrams, $previousFine),
                puritySource: $lot->purity_source->value,
                lotStatus: $lot->status->value,
            );

            return ['result' => $result, 'assay' => $assay, 'lab' => $laboratory];
        }, attempts: 3);

        $this->dispatchEvents($outcome['result'], $outcome['assay'], $command);

        return $outcome['result'];
    }

    /** Mark a certificate as disputed. Purity and fine weight are left alone. */
    public function markDisputed(int $assayId): AssayModel
    {
        return DB::transaction(function () use ($assayId): AssayModel {
            $assay = AssayModel::query()->whereKey($assayId)->lockForUpdate()->first();

            if (! $assay instanceof AssayModel) {
                throw new CustodyEntityNotFoundException('Assay', $assayId);
            }

            $assay->status = AssayStatus::DISPUTED;
            $assay->save();

            return $assay;
        });
    }

    private function dispatchEvents(AssayResult $result, AssayModel $assay, RecordAssayCommand $command): void
    {
        $occurredAt = now()->toIso8601String();

        event(new AssayRecorded(
            lotId: $result->goldLotId,
            assayId: $result->assayId,
            assayCode: $result->assayCode,
            purityX10: $result->newPurityX10,
            grossWeightMg: (int) $assay->gross_weight_mg,
            fineWeightMg: $result->newFineMg,
            laboratoryId: $command->laboratoryId,
            certificateNo: $command->certificateNo,
            method: $command->method->value,
            puritySource: $result->puritySource,
            supersededAssayId: $result->previousAssayId,
            recordedByUserId: $command->recordedByUserId,
            occurredAt: $occurredAt,
        ));

        // Only a re-assay moves an existing book balance, and a zero delta has
        // nothing for the ledger to post (ledger_entries forbids amount = 0).
        if ($result->isReAssay() && $result->changedBalance()) {
            event(new AssayAdjusted(
                lotId: $result->goldLotId,
                ownerOrganizationId: $result->ownerOrganizationId,
                previousAssayId: (int) $result->previousAssayId,
                newAssayId: $result->assayId,
                previousAssayCode: (string) $result->previousAssayCode,
                newAssayCode: $result->assayCode,
                previousPurityX10: $result->previousPurityX10,
                newPurityX10: $result->newPurityX10,
                previousFineMg: $result->previousFineMg,
                newFineMg: $result->newFineMg,
                fineDeltaMg: $result->fineDeltaMg,
                recordedByUserId: $command->recordedByUserId,
                occurredAt: $occurredAt,
            ));
        }
    }
}
