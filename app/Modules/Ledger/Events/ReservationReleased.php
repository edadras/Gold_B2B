<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * RESERVED → AVAILABLE completed, in whole or in part.
 */
final readonly class ReservationReleased
{
    public function __construct(
        public int $organizationId,
        public string $assetType,
        public int $amount,
        public int $reservationEntryId,
        public int $releaseEntryId,
        public string $transactionGroup,
        public bool $partial,
        public int $stillReserved,
    ) {}
}
