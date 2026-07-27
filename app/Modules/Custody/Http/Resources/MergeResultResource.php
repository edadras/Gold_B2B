<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Application\Results\MergeResult;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * The receipt for a logical merge. A merge does not melt anything, so it is
 * lossless by definition and `conserves` must always be true — it is exported
 * so the client can assert that rather than assume it.
 */
final class MergeResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MergeResult $result */
        $result = $this->resource;

        return [
            'operation_id' => $result->operationId,
            'input_lot_ids' => $result->inputLotIds,
            'lot_id' => $result->newLotId,
            'lot_code' => $result->newLotCode,
            'purity_x10' => $result->purityX10,
            'gross_weight_mg' => $result->grossWeightMg,
            'fine_weight_mg' => $result->fineWeightMg,
            'input_fine_mg' => $result->inputFineMg,
            'conserves' => $result->conserves(),
        ] + $this->display($request, [
            'gross_weight_display' => Display::grams($result->grossWeightMg),
            'fine_weight_display' => Display::grams($result->fineWeightMg),
            'purity_display' => Display::purity($result->purityX10),
        ]);
    }
}
