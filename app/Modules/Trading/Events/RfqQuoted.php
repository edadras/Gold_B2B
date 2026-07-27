<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** A member answered an RFQ with a price and a soft reservation. */
final readonly class RfqQuoted
{
    public function __construct(
        public int $rfqId,
        public int $quoteId,
        public string $quoteCode,
        public int $quoterOrganizationId,
        public int $requesterOrganizationId,
        public int $quantityMg,
        public int $pricePerGramRial,
        public string $validUntil,
        public string $occurredAt,
    ) {}
}
