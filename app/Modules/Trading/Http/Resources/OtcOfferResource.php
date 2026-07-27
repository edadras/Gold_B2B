<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Infrastructure\Models\OtcOffer;
use Illuminate\Http\Request;

/**
 * A bilateral OTC negotiation (§2.6).
 *
 * The counterparty's organisation id IS disclosed here, unlike on the order
 * book: an OTC offer is a named, two-party negotiation and the member cannot
 * decide whether to accept without knowing who is asking. `my_role` and
 * `awaiting_me` save the client from re-deriving whose turn it is.
 *
 * @mixin OtcOffer
 */
final class OtcOfferResource extends ApiResource
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
        /** @var OtcOffer $offer */
        $offer = $this->resource;

        $isInitiator = (int) $offer->initiator_organization_id === $this->viewerOrganizationId;

        return [
            'id' => (int) $offer->id,
            'offer_code' => (string) $offer->offer_code,
            'instrument' => $this->instrumentCode,
            'instrument_id' => (int) $offer->instrument_id,
            'initiator_organization_id' => (int) $offer->initiator_organization_id,
            'counterparty_organization_id' => (int) $offer->counterparty_organization_id,
            'counterparty_of_viewer_id' => $isInitiator
                ? (int) $offer->counterparty_organization_id
                : (int) $offer->initiator_organization_id,
            'my_role' => $isInitiator ? 'INITIATOR' : 'COUNTERPARTY',
            // `side` is always stated from the initiator's point of view; the
            // viewer's own side is the mirror when they are the counterparty.
            'side' => $offer->side->value,
            'my_side' => $isInitiator ? $offer->side->value : $offer->side->opposite()->value,
            'quantity_mg' => (int) $offer->quantity_mg,
            'price_rial' => (int) $offer->price_rial,
            'min_purity_x10' => $offer->min_purity_x10 === null ? null : (int) $offer->min_purity_x10,
            'settlement_type' => $offer->settlement_type?->value,
            'delivery_type' => $offer->delivery_type?->value,
            'status' => $offer->status->value,
            'round_count' => (int) $offer->round_count,
            'max_rounds' => (int) $offer->max_rounds,
            'proposer_organization_id' => (int) $offer->proposer_organization_id,
            'awaiting_me' => (int) $offer->proposer_organization_id !== $this->viewerOrganizationId
                && ! $offer->status->isFinal(),
            'trade_id' => $offer->trade_id === null ? null : (int) $offer->trade_id,
            'expires_at' => Display::iso($offer->expires_at),
            'responded_at' => Display::iso($offer->responded_at),
            'closed_at' => Display::iso($offer->closed_at),
        ] + $this->display($request, [
            'quantity_display' => Display::grams((int) $offer->quantity_mg),
            'price_display' => Display::rial((int) $offer->price_rial),
            'expires_at_jalali' => Display::jalali($offer->expires_at),
        ]);
    }
}
