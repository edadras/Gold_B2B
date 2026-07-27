<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/**
 * AmlFlag state machine — docs/11-appendix/02-state-machines.md §2.11.
 *
 *   OPEN            → UNDER_REVIEW
 *   UNDER_REVIEW    → CLEARED | FALSE_POSITIVE | ENHANCED_REVIEW | ESCALATED
 *   ENHANCED_REVIEW → CLEARED | ACTION_TAKEN | ESCALATED
 *   ESCALATED       → ACTION_TAKEN | CLEARED
 *   ACTION_TAKEN, CLEARED, FALSE_POSITIVE are final
 */
enum FlagStatus: string
{
    case OPEN = 'OPEN';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case ENHANCED_REVIEW = 'ENHANCED_REVIEW';
    case CLEARED = 'CLEARED';
    case FALSE_POSITIVE = 'FALSE_POSITIVE';
    case ESCALATED = 'ESCALATED';
    case ACTION_TAKEN = 'ACTION_TAKEN';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::UNDER_REVIEW],
            self::UNDER_REVIEW => [
                self::CLEARED,
                self::FALSE_POSITIVE,
                self::ENHANCED_REVIEW,
                self::ESCALATED,
            ],
            self::ENHANCED_REVIEW => [self::CLEARED, self::ACTION_TAKEN, self::ESCALATED],
            self::ESCALATED => [self::ACTION_TAKEN, self::CLEARED],
            self::CLEARED, self::FALSE_POSITIVE, self::ACTION_TAKEN => [],
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

    /** Open flags are the ones that count against the AML component of F19. */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }
}
