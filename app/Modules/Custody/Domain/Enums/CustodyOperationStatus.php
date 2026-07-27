<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** CustodyOperation state machine — docs/11-appendix/02-state-machines.md §2.13. */
enum CustodyOperationStatus: string
{
    case REQUESTED = 'REQUESTED';
    case APPROVED = 'APPROVED';
    case EXECUTING = 'EXECUTING';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REQUESTED => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED => [self::EXECUTING, self::CANCELLED],
            self::EXECUTING => [self::COMPLETED, self::REJECTED],
            self::COMPLETED, self::REJECTED, self::CANCELLED => [],
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
}
