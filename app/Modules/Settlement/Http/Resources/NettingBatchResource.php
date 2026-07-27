<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Infrastructure\Models\NettingBatchModel;
use App\Modules\Settlement\Infrastructure\Models\NettingPositionModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A netting batch and, crucially, THIS member's position in it (§2.9).
 *
 * Only the viewer's own position is published. The batch-level totals
 * (participant count, gross and net volume) are aggregates that reveal nothing
 * about any single member, but the individual positions of the other
 * participants are their business alone — publishing them would tell every
 * participant exactly how exposed each of its competitors is.
 *
 * @mixin NettingBatchModel
 */
final class NettingBatchResource extends ApiResource
{
    public function __construct(
        mixed $resource,
        private readonly ?NettingPositionModel $myPosition = null,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NettingBatchModel $batch */
        $batch = $this->resource;

        $isGold = $batch->asset_type->value === 'GOLD';

        return [
            'id' => (int) $batch->id,
            'batch_code' => (string) $batch->batch_code,
            'batch_date' => $batch->batch_date?->toDateString(),
            'netting_type' => $batch->netting_type->value,
            'netting_type_label' => $batch->netting_type->label(),
            'asset_type' => $batch->asset_type->value,
            'status' => $batch->status->value,
            'participant_count' => (int) $batch->participant_count,
            'gross_transfer_count' => (int) $batch->gross_transfer_count,
            'net_transfer_count' => (int) $batch->net_transfer_count,
            'gross_volume' => (int) $batch->gross_volume,
            'net_volume' => (int) $batch->net_volume,
            'reduces_transfers' => $batch->reducesTransfers(),
            'proposed_at' => Display::iso($batch->proposed_at),
            'accept_deadline_at' => Display::iso($batch->accept_deadline_at),
            'executed_at' => Display::iso($batch->executed_at),
            'cancelled_at' => Display::iso($batch->cancelled_at),
            'my_position' => $this->position($isGold),
        ] + $this->display($request, [
            'gross_volume_display' => $isGold
                ? Display::grams((int) $batch->gross_volume)
                : Display::rial((int) $batch->gross_volume),
            'net_volume_display' => $isGold
                ? Display::grams((int) $batch->net_volume)
                : Display::rial((int) $batch->net_volume),
            'accept_deadline_jalali' => Display::jalali($batch->accept_deadline_at),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function position(bool $isGold): ?array
    {
        if ($this->myPosition === null) {
            return null;
        }

        $net = (int) $this->myPosition->net_position;

        return [
            'gross_in' => (int) $this->myPosition->gross_in,
            'gross_out' => (int) $this->myPosition->gross_out,
            'net_position' => $net,
            // A positive net means the member receives; negative means it pays.
            'direction' => $net > 0 ? 'RECEIVE' : ($net < 0 ? 'PAY' : 'FLAT'),
            'obligation_count' => (int) $this->myPosition->obligation_count,
            'has_answered' => $this->myPosition->hasAnswered(),
            'accepted_at' => Display::iso($this->myPosition->accepted_at),
            'rejected_at' => Display::iso($this->myPosition->rejected_at),
            'rejection_reason' => $this->myPosition->rejection_reason,
            'net_position_display' => $isGold ? Display::grams($net) : Display::rial($net),
        ];
    }
}
