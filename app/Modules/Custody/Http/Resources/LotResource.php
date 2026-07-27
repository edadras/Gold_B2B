<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A gold lot, as its owner sees it.
 *
 * Built from GoldLotSnapshot rather than GoldLotModel: the snapshot is
 * immutable, so nothing in the HTTP layer can write to a lot by accident.
 *
 * Every weight is an integer milligram and purity an integer ten-thousandth,
 * per AGENT_BRIEF rule 1 — the `_display` companions next to them are strings
 * for humans and are never parsed back.
 *
 * `owner_organization_id` is present, and safe here, because this resource is
 * only ever reachable behind the two-legged ownership check in LotController:
 * the value it carries is always the caller's own id. The unauthenticated QR
 * view uses PublicLotView instead, which does not have the field at all.
 */
final class LotResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var GoldLotSnapshot $lot */
        $lot = $this->resource;

        return [
            'id' => $lot->id,
            'lot_code' => $lot->lotCode,
            'metal_type' => $lot->metalType->value,
            'gross_weight_mg' => $lot->grossWeightMg,
            'purity_x10' => $lot->purityX10,
            'fine_weight_mg' => $lot->fineWeightMg,
            'purity_source' => $lot->puritySource->value,
            'status' => $lot->status->value,
            'origin_type' => $lot->originType->value,
            'owner_organization_id' => $lot->ownerOrganizationId,
            'custodian_type' => $lot->custodianType->value,
            'custodian_id' => $lot->custodianId,
            'vault_box_id' => $lot->vaultBoxId,
            'physical_location' => $lot->physicalLocation,
            'serial_number' => $lot->serialNumber,
            'current_assay_id' => $lot->currentAssayId,
            'generation' => $lot->generation,
            'is_available' => $lot->status->isAllocatable(),
            'is_encumbered' => $lot->status->isEncumbered(),
            'is_order_book_eligible' => $lot->isOrderBookEligible(),
            'created_at' => Display::iso($lot->createdAt),
        ] + $this->display($request, [
            'gross_weight_display' => Display::grams($lot->grossWeightMg),
            'fine_weight_display' => Display::grams($lot->fineWeightMg),
            'purity_display' => Display::purity($lot->purityX10),
            'created_at_jalali' => Display::jalali($lot->createdAt),
        ]);
    }
}
