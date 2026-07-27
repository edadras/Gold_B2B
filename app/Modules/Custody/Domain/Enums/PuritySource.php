<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/**
 * Where a lot's purity figure comes from — docs/03-domain/02-gold-lot-assay.md §2.4.
 *
 * This distinction removes one of the most common sources of dispute in the
 * physical market: only laboratory-certified purity may reach the order book.
 */
enum PuritySource: string
{
    /** Verified certificate from an accredited laboratory. */
    case ASSAYED = 'ASSAYED';

    /** The member stated the purity themselves. OTC only, with explicit warning. */
    case DECLARED = 'DECLARED';

    /** System estimate (e.g. batch average). Internal reporting only. */
    case ESTIMATED = 'ESTIMATED';

    /** Only ASSAYED gold may be listed on the order book. */
    public function isTradableOnOrderBook(): bool
    {
        return $this === self::ASSAYED;
    }

    /** OTC trading is allowed for ASSAYED and DECLARED (the latter with a warning). */
    public function isTradableOtc(): bool
    {
        return $this === self::ASSAYED || $this === self::DECLARED;
    }

    /** Only certified gold may back a credit line. */
    public function isEligibleAsCollateral(): bool
    {
        return $this === self::ASSAYED;
    }

    public function requiresBuyerWarning(): bool
    {
        return $this === self::DECLARED;
    }

    /** Higher is more trustworthy. Used when merging lots. */
    public function trustRank(): int
    {
        return match ($this) {
            self::ASSAYED => 3,
            self::DECLARED => 2,
            self::ESTIMATED => 1,
        };
    }

    /** The weaker of two sources — a merged lot is only as good as its worst input. */
    public function lowerOf(self $other): self
    {
        return $this->trustRank() <= $other->trustRank() ? $this : $other;
    }
}
