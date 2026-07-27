<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * A quote was accepted and turned into a trade. $fullyAccepted is false when
 * the RFQ still has volume open (partial acceptance, §4.7 rule 4).
 */
final readonly class RfqAccepted
{
    public function __construct(
        public int $rfqId,
        public int $quoteId,
        public int $tradeId,
        public int $requesterOrganizationId,
        public int $quoterOrganizationId,
        public int $acceptedMg,
        public int $pricePerGramRial,
        public bool $fullyAccepted,
        public string $occurredAt,
    ) {}
}
