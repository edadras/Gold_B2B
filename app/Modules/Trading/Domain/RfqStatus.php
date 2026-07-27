<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * RFQ state machine — docs/11-appendix/02-state-machines.md §2.6.
 *
 *   OPEN                -> QUOTED | CANCELLED | EXPIRED
 *   QUOTED              -> ACCEPTED | PARTIALLY_ACCEPTED | CANCELLED | EXPIRED
 *   PARTIALLY_ACCEPTED  -> ACCEPTED | EXPIRED
 *   ACCEPTED / CANCELLED / EXPIRED are final.
 */
enum RfqStatus: string
{
    case OPEN = 'OPEN';
    case QUOTED = 'QUOTED';
    case PARTIALLY_ACCEPTED = 'PARTIALLY_ACCEPTED';
    case ACCEPTED = 'ACCEPTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::QUOTED, self::CANCELLED, self::EXPIRED],
            self::QUOTED => [
                self::ACCEPTED, self::PARTIALLY_ACCEPTED,
                self::CANCELLED, self::EXPIRED,
            ],
            self::PARTIALLY_ACCEPTED => [self::ACCEPTED, self::EXPIRED],
            self::ACCEPTED, self::CANCELLED, self::EXPIRED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Quotes may still arrive. */
    public function acceptsQuotes(): bool
    {
        return in_array($this, [self::OPEN, self::QUOTED, self::PARTIALLY_ACCEPTED], true);
    }
}
