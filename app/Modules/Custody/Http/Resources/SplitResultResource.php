<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Application\Results\SplitResult;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * The receipt for a split.
 *
 * `fine_loss_mg` is exported because it is not a rounding artefact the client
 * may ignore: it is fine gold that existed on the parent and exists on no
 * child, and the Ledger posts it as PROCESSING_LOSS / ROUNDING. `conserves`
 * lets the client assert Σ children + loss == parent for itself.
 */
final class SplitResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SplitResult $result */
        $result = $this->resource;

        return [
            'operation_id' => $result->operationId,
            'parent_lot_id' => $result->parentLotId,
            'parent_lot_code' => $result->parentLotCode,
            'parent_gross_mg' => $result->parentGrossMg,
            'parent_fine_mg' => $result->parentFineMg,
            'child_lot_ids' => $result->childLotIds,
            'child_lot_codes' => $result->childLotCodes,
            'child_gross_mg' => $result->childGrossMg,
            'child_fine_mg' => $result->childFineMg,
            'child_count' => $result->childCount(),
            'gross_loss_mg' => $result->grossLossMg,
            'fine_loss_mg' => $result->fineLossMg,
            'conserves' => $result->conserves(),
        ] + $this->display($request, [
            'parent_gross_display' => Display::grams($result->parentGrossMg),
            'parent_fine_display' => Display::grams($result->parentFineMg),
            'fine_loss_display' => Display::grams($result->fineLossMg),
        ]);
    }
}
