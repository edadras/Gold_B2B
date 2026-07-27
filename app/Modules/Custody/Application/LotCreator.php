<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Application\Commands\NewLotSpec;
use App\Modules\Custody\Domain\Exceptions\WeightConservationException;
use App\Modules\Custody\Domain\ValueObjects\LotCode;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;

/**
 * Single point where a gold_lots row is born.
 *
 * Every path that creates metal (deposit, split, merge, melt) goes through
 * here so the lot code, QR token and F1 fine weight are produced identically.
 */
final readonly class LotCreator
{
    public function __construct(private QrTokenService $qrTokens) {}

    public function create(NewLotSpec $spec): GoldLotModel
    {
        $fine = $spec->fineWeight();

        if ($fine->milligrams > $spec->gross->milligrams) {
            throw new WeightConservationException(
                operation: 'CREATE_LOT',
                dimension: 'fine<=gross',
                inputMg: $spec->gross->milligrams,
                outputMg: $fine->milligrams,
                lossMg: 0,
            );
        }

        $lot = new GoldLotModel;
        // lot_code is NOT NULL UNIQUE and its sequence is the row id, so it can
        // only be stamped after the insert. The placeholder keeps the unique
        // index satisfied for the fraction of a transaction in between.
        $lot->lot_code = LotCode::placeholder();
        $lot->metal_type = $spec->metalType;
        $lot->gross_weight_mg = $spec->gross->milligrams;
        $lot->purity_x10 = $spec->purity->value;
        $lot->fine_weight_mg = $fine->milligrams;
        $lot->purity_source = $spec->puritySource;
        $lot->serial_number = $spec->serialNumber;
        $lot->hallmark_code = $spec->hallmarkCode;
        $lot->shape = $spec->shape;
        $lot->qr_token = $this->qrTokens->generate();
        $lot->origin_type = $spec->originType;
        $lot->refiner_id = $spec->refinerId;
        $lot->refined_at = $spec->refinedAt;
        $lot->current_assay_id = $spec->currentAssayId;
        $lot->generation = $spec->generation;
        $lot->owner_organization_id = $spec->ownerOrganizationId;
        $lot->custodian_type = $spec->custodianType;
        $lot->custodian_id = $spec->custodianId;
        $lot->vault_box_id = $spec->vaultBoxId;
        $lot->physical_location = $spec->physicalLocation;
        $lot->status = $spec->status;
        $lot->version = 0;
        $lot->created_by_user_id = $spec->createdByUserId;
        $lot->save();

        $lot->lot_code = LotCode::forSequence((int) $lot->id)->value;
        $lot->save();

        return $lot;
    }
}
