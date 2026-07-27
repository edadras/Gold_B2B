<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** A direct, private offer was sent to a named counterparty (§4.6). */
final readonly class OtcOfferCreated
{
    public function __construct(
        public int $offerId,
        public string $offerCode,
        public int $instrumentId,
        public int $initiatorOrganizationId,
        public int $counterpartyOrganizationId,
        public string $side,
        public int $quantityMg,
        public int $priceRial,
        public string $expiresAt,
        public string $occurredAt,
    ) {}
}
