<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\OtcOfferStatus;
use App\Modules\Trading\Domain\SettlementType;
use App\Modules\Trading\Domain\Side;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $offer_code
 * @property int $instrument_id
 * @property int $initiator_organization_id
 * @property int $counterparty_organization_id
 * @property int $proposer_organization_id
 * @property Side $side
 * @property int $quantity_mg
 * @property int $price_rial
 * @property OtcOfferStatus $status
 * @property int $round_count
 * @property int $max_rounds
 * @property ?int $reservation_entry_id
 * @property int $reserved_amount
 * @property CarbonImmutable $expires_at
 */
final class OtcOffer extends Model
{
    protected $table = 'otc_offers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'status' => OtcOfferStatus::class,
            'settlement_type' => SettlementType::class,
            'delivery_type' => DeliveryType::class,
            'quantity_mg' => 'integer',
            'price_rial' => 'integer',
            'min_purity_x10' => 'integer',
            'round_count' => 'integer',
            'max_rounds' => 'integer',
            'reservation_entry_id' => 'integer',
            'reserved_amount' => 'integer',
            'expires_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<OtcOfferHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OtcOfferHistory::class, 'otc_offer_id');
    }

    /** The party who must respond to the terms currently on the table. */
    public function responderOrganizationId(): int
    {
        return $this->proposer_organization_id === $this->initiator_organization_id
            ? $this->counterparty_organization_id
            : $this->initiator_organization_id;
    }

    public function involves(int $organizationId): bool
    {
        return $organizationId === $this->initiator_organization_id
            || $organizationId === $this->counterparty_organization_id;
    }

    /** Who sells under these terms: `side` is always the initiator's direction. */
    public function sellerOrganizationId(): int
    {
        return $this->side->isSell()
            ? $this->initiator_organization_id
            : $this->counterparty_organization_id;
    }

    public function buyerOrganizationId(): int
    {
        return $this->side->isSell()
            ? $this->counterparty_organization_id
            : $this->initiator_organization_id;
    }
}
