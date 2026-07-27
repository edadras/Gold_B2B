<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/**
 * Laboratory accreditation — docs/03-domain/02-gold-lot-assay.md §2.9.
 *
 * | TIER_1       | certificate accepted directly            |
 * | TIER_2       | accepted, 5% random re-assay sampling    |
 * | UNACCREDITED | recorded as DECLARED, never ASSAYED      |
 */
enum AccreditationLevel: string
{
    case TIER_1 = 'TIER_1';
    case TIER_2 = 'TIER_2';
    case UNACCREDITED = 'UNACCREDITED';

    /** The strongest purity source a certificate from this lab can produce. */
    public function grantedPuritySource(): PuritySource
    {
        return $this === self::UNACCREDITED
            ? PuritySource::DECLARED
            : PuritySource::ASSAYED;
    }

    /** Share of certificates pulled for random re-assay, in basis points. */
    public function randomSamplingBps(): int
    {
        return match ($this) {
            self::TIER_1 => 0,
            self::TIER_2 => 500,      // 5%
            self::UNACCREDITED => 10_000,
        };
    }
}
