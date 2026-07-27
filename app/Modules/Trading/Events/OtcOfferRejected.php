<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/** The offer was declined or withdrawn; any reservation behind it is released. */
final readonly class OtcOfferRejected
{
    public function __construct(
        public int $offerId,
        public int $actorOrganizationId,
        public int $counterpartyOrganizationId,
        public string $status,
        public ?string $reason,
        public string $occurredAt,
    ) {}
}
