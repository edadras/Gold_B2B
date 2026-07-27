<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\PricePerFineGram;

/**
 * F7 — premium ("حباب") of the market price over the intrinsic value, in bps.
 *
 *   premium_bps = floor((market − intrinsic) × 10000 / intrinsic)
 *
 * Reference vector: market 78,480,000 vs intrinsic 52,831,649 → 4,854 bps.
 *
 * A negative result is legitimate (discount). A zero intrinsic value yields
 * null rather than an error — docs/11-appendix/01-formulas.md §1.11 case 6.
 */
final class PremiumCalculator
{
    private const BPS_SCALE = 10_000;

    public function premiumBps(PricePerFineGram $market, PricePerFineGram $intrinsic): ?int
    {
        if ($intrinsic->rial === 0) {
            return null;
        }

        $delta = IntMath::sub($market->rial, $intrinsic->rial);

        return IntMath::mulDivFloor($delta, self::BPS_SCALE, $intrinsic->rial);
    }

    /** Convenience for the raw rial spread between market and intrinsic. */
    public function premiumRial(PricePerFineGram $market, PricePerFineGram $intrinsic): int
    {
        return IntMath::sub($market->rial, $intrinsic->rial);
    }

    /**
     * A sudden swing into discount territory is a manipulation signal
     * (docs/03-domain/07-pricing.md §7.5), so callers get a cheap predicate.
     */
    public function isDiscount(PricePerFineGram $market, PricePerFineGram $intrinsic): bool
    {
        $bps = $this->premiumBps($market, $intrinsic);

        return $bps !== null && $bps < 0;
    }
}
