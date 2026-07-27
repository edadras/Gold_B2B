<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Domain\RfqVisibility;
use App\Modules\Trading\Infrastructure\Models\Rfq;
use Illuminate\Http\Request;

/**
 * A request for quote (§2.7).
 *
 * `recipient_org_ids` is only shown to the requester: an invited member has no
 * business learning who else was asked, and an ANONYMOUS RFQ additionally hides
 * the requester itself from anyone who is not the requester.
 *
 * @mixin Rfq
 */
final class RfqResource extends ApiResource
{
    public function __construct(
        mixed $resource,
        private readonly int $viewerOrganizationId,
        private readonly ?string $instrumentCode = null,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Rfq $rfq */
        $rfq = $this->resource;

        $isRequester = (int) $rfq->organization_id === $this->viewerOrganizationId;
        $anonymous = $rfq->visibility === RfqVisibility::ANONYMOUS;

        return [
            'id' => (int) $rfq->id,
            'rfq_code' => (string) $rfq->rfq_code,
            'instrument' => $this->instrumentCode,
            'instrument_id' => (int) $rfq->instrument_id,
            // Anonymity is one-way: the requester always sees itself.
            'organization_id' => $isRequester || ! $anonymous ? (int) $rfq->organization_id : null,
            'is_mine' => $isRequester,
            'side' => $rfq->side->value,
            'quantity_mg' => (int) $rfq->quantity_mg,
            'accepted_mg' => (int) $rfq->accepted_mg,
            'remaining_mg' => $rfq->remainingMg(),
            'min_purity_x10' => $rfq->min_purity_x10 === null ? null : (int) $rfq->min_purity_x10,
            'settlement_type' => $rfq->settlement_type?->value,
            'delivery_type' => $rfq->delivery_type?->value,
            'visibility' => $rfq->visibility->value,
            'allow_partial' => (bool) $rfq->allow_partial,
            'recipient_organization_ids' => $isRequester ? ($rfq->recipient_org_ids ?? []) : null,
            'status' => $rfq->status->value,
            'expires_at' => Display::iso($rfq->expires_at),
            'closed_at' => Display::iso($rfq->closed_at),
        ] + $this->display($request, [
            'quantity_display' => Display::grams((int) $rfq->quantity_mg),
            'remaining_display' => Display::grams($rfq->remainingMg()),
            'min_purity_display' => Display::purity($rfq->min_purity_x10 === null ? null : (int) $rfq->min_purity_x10),
            'expires_at_jalali' => Display::jalali($rfq->expires_at),
        ]);
    }
}
