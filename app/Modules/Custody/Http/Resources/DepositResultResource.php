<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Application\Results\DepositResult;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * The deposit receipt of docs/03-domain/06-custody-vault.md §6.3 step 9.
 *
 * `pending_assay_lot_ids` is the field that matters to the member: a piece
 * that arrived without a certificate from an accredited laboratory is booked
 * UNDER_ASSAY and cannot be traded until one is recorded. Hiding that would
 * leave the member wondering why their new bar is not sellable.
 */
final class DepositResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DepositResult $result */
        $result = $this->resource;

        return [
            'operation_id' => $result->operationId,
            'vault_id' => $result->vaultId,
            'organization_id' => $result->ownerOrganizationId,
            'lot_ids' => $result->lotIds,
            'lot_codes' => $result->lotCodes,
            'piece_count' => $result->pieceCount(),
            'total_gross_mg' => $result->totalGrossMg,
            'total_fine_mg' => $result->totalFineMg,
            'pending_assay_lot_ids' => array_map('intval', $result->pendingAssayLotIds),
        ] + $this->display($request, [
            'total_gross_display' => Display::grams($result->totalGrossMg),
            'total_fine_display' => Display::grams($result->totalFineMg),
        ]);
    }
}
