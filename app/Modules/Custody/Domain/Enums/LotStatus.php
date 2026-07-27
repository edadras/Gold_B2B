<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/**
 * GoldLot state machine — docs/11-appendix/02-state-machines.md §2.5.
 *
 * Hard rules from docs/03-domain/02-gold-lot-assay.md §2.3:
 *  - a lot in RESERVED or IN_SETTLEMENT cannot be sold again
 *  - CONSUMED is terminal and the row is never deleted (lineage depends on it)
 */
enum LotStatus: string
{
    case UNDER_ASSAY = 'UNDER_ASSAY';
    case AVAILABLE = 'AVAILABLE';
    case RESERVED = 'RESERVED';
    case IN_SETTLEMENT = 'IN_SETTLEMENT';
    case IN_TRANSIT = 'IN_TRANSIT';
    case ON_HOLD = 'ON_HOLD';
    case WITHDRAWN = 'WITHDRAWN';
    case CONSUMED = 'CONSUMED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::UNDER_ASSAY => [
                self::AVAILABLE, self::ON_HOLD, self::CONSUMED,
            ],
            self::AVAILABLE => [
                self::RESERVED, self::ON_HOLD, self::IN_TRANSIT,
                self::UNDER_ASSAY, self::CONSUMED, self::WITHDRAWN,
            ],
            self::RESERVED => [
                self::IN_SETTLEMENT, self::AVAILABLE, self::ON_HOLD,
            ],
            self::IN_SETTLEMENT => [
                self::AVAILABLE, self::RESERVED, self::ON_HOLD,
            ],
            self::IN_TRANSIT => [
                self::AVAILABLE, self::ON_HOLD,
            ],
            self::ON_HOLD => [
                self::AVAILABLE,
            ],
            self::WITHDRAWN => [
                self::AVAILABLE,
            ],
            self::CONSUMED => [],
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

    /** Can this lot be picked up by the allocator / offered for sale? */
    public function isAllocatable(): bool
    {
        return $this === self::AVAILABLE;
    }

    /** Is the metal still inside the platform's accounting perimeter? */
    public function isLive(): bool
    {
        return $this !== self::CONSUMED && $this !== self::WITHDRAWN;
    }

    /** Locked by an in-flight trade or settlement. */
    public function isEncumbered(): bool
    {
        return $this === self::RESERVED || $this === self::IN_SETTLEMENT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
