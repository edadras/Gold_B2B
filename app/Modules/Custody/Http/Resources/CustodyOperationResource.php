<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A row of `custody_operations`, which is what both `GET /vault/deposits` and
 * `GET /vault/withdrawals` return — the two lists differ only by
 * `operation_type` (VAULT_IN / VAULT_OUT).
 *
 * `signature_data`, `photos` and `notes` stay out: the first two are evidence
 * captured at the counter for the audit trail, and `notes` is where the vault
 * officer's free text about the receiver goes.
 *
 * @mixin CustodyOperationModel
 */
final class CustodyOperationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CustodyOperationModel $operation */
        $operation = $this->resource;

        return [
            'id' => (int) $operation->id,
            'operation_type' => $operation->operation_type->value,
            'status' => $operation->status->value,
            'vault_id' => $operation->vault_id === null ? null : (int) $operation->vault_id,
            'organization_id' => $operation->organization_id === null ? null : (int) $operation->organization_id,
            'lot_ids' => array_map('intval', $operation->input_lot_ids ?? []),
            'output_lot_ids' => array_map('intval', $operation->output_lot_ids ?? []),
            'input_fine_mg' => (int) $operation->input_fine_mg,
            'output_fine_mg' => (int) $operation->output_fine_mg,
            'loss_fine_mg' => (int) $operation->loss_fine_mg,
            'loss_gross_mg' => (int) $operation->loss_gross_mg,
            'conserves' => $operation->conserves(),
            'requires_extra_approval' => (bool) $operation->requires_extra_approval,
            'from_location' => $operation->from_location,
            'to_location' => $operation->to_location,
            'reason' => $operation->reason,
            'requested_by_user_id' => (int) $operation->requested_by_user_id,
            'approved_by_user_id' => $operation->approved_by_user_id === null
                ? null
                : (int) $operation->approved_by_user_id,
            'requested_at' => Display::iso($operation->requested_at),
            'approved_at' => Display::iso($operation->approved_at),
            'executed_at' => Display::iso($operation->executed_at),
        ] + $this->display($request, [
            'input_fine_display' => Display::grams((int) $operation->input_fine_mg),
            'output_fine_display' => Display::grams((int) $operation->output_fine_mg),
            'loss_fine_display' => Display::grams((int) $operation->loss_fine_mg),
            'requested_at_jalali' => Display::jalali($operation->requested_at),
            'approved_at_jalali' => Display::jalali($operation->approved_at),
            'executed_at_jalali' => Display::jalali($operation->executed_at),
        ]);
    }
}
