<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Contracts\DTO\AssaySnapshot;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One assay certificate from a lot's history.
 *
 * Superseded certificates are included by AssayReaderInterface::historyForLot
 * on purpose — a re-assay is the usual trigger for a purity dispute, and the
 * member needs to see what the previous certificate said.
 */
final class AssayResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AssaySnapshot $assay */
        $assay = $this->resource;

        return [
            'id' => $assay->id,
            'assay_code' => $assay->assayCode,
            'gold_lot_id' => $assay->goldLotId,
            'certificate_no' => $assay->certificateNo,
            'laboratory_id' => $assay->laboratoryId,
            'laboratory_name' => $assay->laboratoryName,
            'method' => $assay->method->value,
            'gross_weight_mg' => $assay->grossWeightMg,
            'purity_x10' => $assay->purityX10,
            'fine_weight_mg' => $assay->fineWeightMg,
            'status' => $assay->status->value,
            'is_authoritative' => $assay->isAuthoritative(),
            'superseded_by_id' => $assay->supersededById,
            'assayed_at' => Display::iso($assay->assayedAt),
            'valid_until' => Display::iso($assay->validUntil),
            'verified_by_lab_at' => Display::iso($assay->verifiedByLabAt),
        ] + $this->display($request, [
            'gross_weight_display' => Display::grams($assay->grossWeightMg),
            'fine_weight_display' => Display::grams($assay->fineWeightMg),
            'purity_display' => Display::purity($assay->purityX10),
            'assayed_at_jalali' => Display::jalali($assay->assayedAt),
        ]);
    }
}
