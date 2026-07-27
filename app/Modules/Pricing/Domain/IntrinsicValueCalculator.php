<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use InvalidArgumentException;

/**
 * F6 — intrinsic value of one gram of pure gold, in rial.
 *
 *                 ounce_usd × usd_irr
 *   price_per_g = ───────────────────
 *                     31.1034768
 *
 * Integer form (docs/11-appendix/01-formulas.md F6):
 *   floor(ounce_usd_micro × usd_irr × 10 / 311_034_768)
 *
 * Reference vector: (2_650_400_000, 620_000) → 52,831,649 rial/g.
 */
final class IntrinsicValueCalculator
{
    /** Troy ounce expressed in milligrams × 10^4 — docs/11-appendix/01-formulas.md §1.1. */
    private const OUNCE_MG_X10000 = 311_034_768;

    /**
     * Bridges micro-dollars-per-ounce and rial-per-gram: the ×10^4 scaling of
     * OUNCE_MG_X10000 divided by the ×10^6 scaling of the ounce price and
     * multiplied by the 10^3 milligrams in a gram leaves exactly ×10.
     */
    private const SCALE_BRIDGE = 10;

    /**
     * @param  int  $ounceUsdMicro  XAU/USD × 10^6
     * @param  int  $usdIrr  rial per US dollar, whole rial
     */
    public function pricePerFineGram(int $ounceUsdMicro, int $usdIrr): PricePerFineGram
    {
        if ($ounceUsdMicro <= 0) {
            throw new InvalidArgumentException('Ounce price must be positive');
        }

        if ($usdIrr <= 0) {
            throw new InvalidArgumentException('USD/IRR rate must be positive');
        }

        return PricePerFineGram::fromRial(
            IntMath::mulDivFloor(
                $ounceUsdMicro,
                IntMath::mul($usdIrr, self::SCALE_BRIDGE),
                self::OUNCE_MG_X10000,
            )
        );
    }

    /**
     * Value of one gram at a given purity: fine gram price × P / 1000
     * (docs/03-domain/07-pricing.md §7.2). Purity is held in ten-thousandths,
     * so the divisor is Purity::SCALE.
     */
    public function pricePerGramAtPurity(PricePerFineGram $fineGramPrice, Purity $purity): PricePerFineGram
    {
        return PricePerFineGram::fromRial(
            IntMath::mulDivFloor($fineGramPrice->rial, $purity->value, Purity::SCALE)
        );
    }

    /**
     * Same computation expressed against a mesghal quote, for markets that
     * publish مظنه rather than an ounce price.
     */
    public function pricePerFineGramFromMesghal(int $mesghalRial, Purity $purity): PricePerFineGram
    {
        if ($mesghalRial <= 0) {
            throw new InvalidArgumentException('Mesghal quote must be positive');
        }

        if ($purity->value === 0) {
            throw new InvalidArgumentException('Cannot derive a fine gram price at zero purity');
        }

        $mesghalMgX10 = (int) config('goldb2b.units.mesghal_mg_x10', 46_083);

        // rial per gross milligram × 10, then lift to fine grams.
        $perGrossGram = IntMath::mulDivFloor($mesghalRial, 10_000, $mesghalMgX10);

        return PricePerFineGram::fromRial(
            IntMath::mulDivFloor($perGrossGram, Purity::SCALE, $purity->value)
        );
    }
}
