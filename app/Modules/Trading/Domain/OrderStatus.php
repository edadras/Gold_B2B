<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * Order state machine — transcribed from docs/11-appendix/02-state-machines.md §2.3.
 *
 *   PENDING           -> OPEN | REJECTED
 *   OPEN              -> PARTIALLY_FILLED | FILLED | CANCELLED | EXPIRED
 *   PARTIALLY_FILLED  -> FILLED | CANCELLED | EXPIRED
 *   FILLED / CANCELLED / REJECTED / EXPIRED are final.
 *
 * Side effects of each transition are listed in the same section and are
 * applied by OrderStateMachine, not here: this enum stays pure.
 */
enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case OPEN = 'OPEN';
    case PARTIALLY_FILLED = 'PARTIALLY_FILLED';
    case FILLED = 'FILLED';
    case CANCELLED = 'CANCELLED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::OPEN, self::REJECTED],
            self::OPEN => [self::PARTIALLY_FILLED, self::FILLED, self::CANCELLED, self::EXPIRED],
            self::PARTIALLY_FILLED => [self::FILLED, self::CANCELLED, self::EXPIRED],
            self::FILLED, self::CANCELLED, self::REJECTED, self::EXPIRED => [],
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

    /** Resting in the book and therefore matchable. */
    public function isActive(): bool
    {
        return in_array($this, [self::OPEN, self::PARTIALLY_FILLED], true);
    }

    /** The two statuses the matching engine will consider as makers. */
    public static function matchable(): array
    {
        return [self::OPEN->value, self::PARTIALLY_FILLED->value];
    }

    /** Statuses whose remaining reservation must be handed back. */
    public function releasesRemainingReservation(): bool
    {
        return in_array($this, [self::CANCELLED, self::EXPIRED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'در حال بررسی',
            self::OPEN => 'باز',
            self::PARTIALLY_FILLED => 'اجرای جزئی',
            self::FILLED => 'اجراشده',
            self::CANCELLED => 'لغوشده',
            self::REJECTED => 'ردشده',
            self::EXPIRED => 'منقضی',
        };
    }
}
