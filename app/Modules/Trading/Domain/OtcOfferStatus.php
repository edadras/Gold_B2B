<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * OTC offer state machine — docs/11-appendix/02-state-machines.md §2.7.
 *
 *   PENDING   -> ACCEPTED | COUNTERED | REJECTED | CANCELLED | EXPIRED
 *   COUNTERED -> ACCEPTED | COUNTERED | REJECTED | EXPIRED
 *
 * COUNTERED -> COUNTERED is legal but bounded: §4.6 caps the negotiation at
 * five round trips, after which the offer expires. The cap is enforced by
 * OtcService and by the chk_otc_rounds CHECK, not by the enum, because a state
 * machine cannot count.
 */
enum OtcOfferStatus: string
{
    case PENDING = 'PENDING';
    case COUNTERED = 'COUNTERED';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [
                self::ACCEPTED, self::COUNTERED, self::REJECTED,
                self::CANCELLED, self::EXPIRED,
            ],
            self::COUNTERED => [
                self::ACCEPTED, self::COUNTERED, self::REJECTED, self::EXPIRED,
            ],
            self::ACCEPTED, self::REJECTED, self::CANCELLED, self::EXPIRED => [],
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

    /** Terms are on the table and may still be acted on. */
    public function isNegotiable(): bool
    {
        return in_array($this, [self::PENDING, self::COUNTERED], true);
    }
}
