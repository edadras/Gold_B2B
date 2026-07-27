<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * Market session state machine — docs/11-appendix/02-state-machines.md §2.10.
 *
 *   SCHEDULED -> PRE_OPEN | CLOSED
 *   PRE_OPEN  -> OPEN | CLOSED
 *   OPEN      -> PAUSED | CLOSED
 *   PAUSED    -> OPEN | CLOSED
 *   CLOSED is final for that day.
 */
enum MarketSessionStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case PRE_OPEN = 'PRE_OPEN';
    case OPEN = 'OPEN';
    case PAUSED = 'PAUSED';
    case CLOSED = 'CLOSED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::SCHEDULED => [self::PRE_OPEN, self::CLOSED],
            self::PRE_OPEN => [self::OPEN, self::CLOSED],
            self::OPEN => [self::PAUSED, self::CLOSED],
            self::PAUSED => [self::OPEN, self::CLOSED],
            self::CLOSED => [],
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

    /** Orders may be entered — PRE_OPEN accepts them but does not match (§4.8). */
    public function acceptsOrders(): bool
    {
        return in_array($this, [self::PRE_OPEN, self::OPEN], true);
    }

    /** Continuous matching runs only while OPEN. */
    public function matches(): bool
    {
        return $this === self::OPEN;
    }

    /** Cancellation stays available during a circuit-breaker pause (§4.8). */
    public function allowsCancellation(): bool
    {
        return $this !== self::SCHEDULED;
    }

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'برنامه‌ریزی‌شده',
            self::PRE_OPEN => 'پیش‌گشایش',
            self::OPEN => 'باز',
            self::PAUSED => 'متوقف',
            self::CLOSED => 'بسته',
        };
    }
}
