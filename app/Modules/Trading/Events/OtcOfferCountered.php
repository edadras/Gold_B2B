<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** One side re-priced the offer. $roundNo counts from 1 at the first counter. */
final readonly class OtcOfferCountered
{
    public function __construct(
        public int $offerId,
        public int $roundNo,
        public int $maxRounds,
        public int $proposerOrganizationId,
        public int $responderOrganizationId,
        public int $quantityMg,
        public int $priceRial,
        public string $occurredAt,
    ) {}
}
