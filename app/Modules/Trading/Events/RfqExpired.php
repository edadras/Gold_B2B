<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** The RFQ window closed; every still-pending quote expired with it. */
final readonly class RfqExpired
{
    public function __construct(
        public int $rfqId,
        public int $organizationId,
        public int $quantityMg,
        public int $acceptedMg,
        public int $expiredQuoteCount,
        public string $occurredAt,
    ) {}
}
