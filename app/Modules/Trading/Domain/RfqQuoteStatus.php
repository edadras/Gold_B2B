<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * RfqQuote state machine — docs/11-appendix/02-state-machines.md §2.6.
 *
 *   PENDING -> ACCEPTED | REJECTED | WITHDRAWN | EXPIRED
 *
 * Every outcome is final: rule 3 of §4.7 says a quote cannot be edited once
 * sent, only withdrawn before acceptance.
 */
enum RfqQuoteStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
    case WITHDRAWN = 'WITHDRAWN';
    case EXPIRED = 'EXPIRED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::ACCEPTED, self::REJECTED, self::WITHDRAWN, self::EXPIRED],
            self::ACCEPTED, self::REJECTED, self::WITHDRAWN, self::EXPIRED => [],
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
