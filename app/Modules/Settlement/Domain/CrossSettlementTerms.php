<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * The arithmetic of a cross settlement — docs/03-domain/05-settlement.md §5.7.
 *
 * The document's worked case, and the numbers this class produces for it:
 *
 *     rial owed by A to B      5,000,000,000
 *     gold owed by B to A            100.000 g   (100,000 mg)
 *     agreed rate               78,500,000 rial / fine gram
 *
 *     the metal is worth        7,850,000,000 rial, which covers the debt, so
 *     gold applied  = floor(5,000,000,000 × 1000 ÷ 78,500,000)
 *                   = 63,694 mg                  (§5.7's «63.694g»)
 *     its worth     = floor(63,694 × 78,500,000 ÷ 1000)
 *                   = 4,999,979,000 rial
 *     rounding      = 5,000,000,000 − 4,999,979,000 = 21,000 rial
 *     gold left     = 100,000 − 63,694 = 36,306 mg   (§5.7's «36.306g»)
 *
 * **Why 63,694 and not 63,694.267…** Both divisions floor. A milligram is the
 * smallest unit of metal the system can move, so the debtor hands over whole
 * milligrams and never a fraction; flooring means it hands over no more than
 * the debt is worth, which is the direction that cannot create gold out of
 * nothing. The 21,000 rial the flooring leaves behind is real value and is
 * accounted for explicitly — CrossSettlementService posts it to the
 * ROUNDING_DIFFERENCE system account — rather than being dropped, which is what
 * "conservation holds" means here.
 *
 * When the metal is worth LESS than the debt the split is the other way round:
 * all of it is applied, it discharges exactly what it is worth, there is no
 * dust, and the unpaid rial stays outstanding on the original settlement.
 *
 * Pure, integer-only and free of any market lookup: the rate is an input,
 * because §5.7 requires the rate the two members agreed on — «ثبت شفاف نرخ
 * تبدیل استفاده‌شده» — and not whatever the market happens to say when someone
 * clicks execute.
 */
final readonly class CrossSettlementTerms
{
    private function __construct(
        /** What the debtor owed in rial before the cross. */
        public int $rialObligationRial,
        /** What the creditor owed in fine milligrams before the cross. */
        public int $goldObligationMg,
        /** The rate both parties agreed, rial per fine gram. */
        public int $agreedRateRial,
        /** Metal actually applied to the rial debt. */
        public int $goldAppliedMg,
        /** Rial debt discharged by that metal. */
        public int $rialDischargedRial,
        /** What the applied metal is worth at the agreed rate. */
        public int $goldValueRial,
        /** Dust the flooring left: discharged − worth. Never negative. */
        public int $roundingRial,
        /** Metal still owed after the cross. */
        public int $goldRemainingMg,
        /** Rial still owed after the cross. */
        public int $rialRemainingRial,
    ) {}

    public static function compute(
        int $rialObligationRial,
        int $goldObligationMg,
        PricePerFineGram $agreedRate,
    ): self {
        $goldObligationValue = $agreedRate->valueOf(FineWeight::fromMilligrams($goldObligationMg))->amount;

        if ($goldObligationValue >= $rialObligationRial) {
            // The metal covers the debt: take only as much of it as the debt
            // is worth, rounded down to whole milligrams.
            $goldAppliedMg = IntMath::mulDivFloor(
                $rialObligationRial,
                Weight::MG_PER_GRAM,
                $agreedRate->rial,
            );
            $rialDischarged = $rialObligationRial;
        } else {
            // The metal does not cover the debt: all of it goes, and it
            // discharges exactly what it is worth. No dust — the discharged
            // amount IS the floored valuation.
            $goldAppliedMg = $goldObligationMg;
            $rialDischarged = $goldObligationValue;
        }

        $goldValue = $agreedRate->valueOf(FineWeight::fromMilligrams($goldAppliedMg))->amount;

        return new self(
            rialObligationRial: $rialObligationRial,
            goldObligationMg: $goldObligationMg,
            agreedRateRial: $agreedRate->rial,
            goldAppliedMg: $goldAppliedMg,
            rialDischargedRial: $rialDischarged,
            goldValueRial: $goldValue,
            roundingRial: IntMath::sub($rialDischarged, $goldValue),
            goldRemainingMg: IntMath::sub($goldObligationMg, $goldAppliedMg),
            rialRemainingRial: IntMath::sub($rialObligationRial, $rialDischarged),
        );
    }

    public function crossesAnything(): bool
    {
        return $this->goldAppliedMg > 0 && $this->rialDischargedRial > 0;
    }

    /** True when the rial obligation is gone entirely. */
    public function dischargesRialInFull(): bool
    {
        return $this->rialRemainingRial === 0;
    }

    /**
     * The identity every caller relies on: what the debt gave up equals what
     * the metal was worth plus the dust the platform absorbed.
     */
    public function conserves(): bool
    {
        return $this->rialDischargedRial === IntMath::add($this->goldValueRial, $this->roundingRial);
    }
}
