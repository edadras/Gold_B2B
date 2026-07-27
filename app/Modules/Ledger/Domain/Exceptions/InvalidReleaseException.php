<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A release that does not correspond to an outstanding reservation:
 * the entry is not a RESERVE credit, or more is being released than remains.
 */
final class InvalidReleaseException extends DomainException
{
    public function __construct(
        public readonly int $reservationEntryId,
        public readonly string $why,
        public readonly int $requested = 0,
        public readonly int $outstanding = 0,
    ) {
        parent::__construct("Cannot release reservation {$reservationEntryId}: {$why}");
    }

    public function errorCode(): string
    {
        return 'INVALID_RELEASE';
    }

    public function userMessage(): string
    {
        return 'آزادسازی این رزرو امکان‌پذیر نیست.';
    }

    public function details(): array
    {
        return [
            'reservationEntryId' => $this->reservationEntryId,
            'reason' => $this->why,
            'requested' => $this->requested,
            'outstanding' => $this->outstanding,
        ];
    }
}
