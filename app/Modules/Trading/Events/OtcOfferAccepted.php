<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** The negotiation closed on agreed terms and produced a trade. */
final readonly class OtcOfferAccepted
{
    public function __construct(
        public int $offerId,
        public int $tradeId,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $quantityMg,
        public int $priceRial,
        public int $rounds,
        public string $occurredAt,
    ) {}
}
