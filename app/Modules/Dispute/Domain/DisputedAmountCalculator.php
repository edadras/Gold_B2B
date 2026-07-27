<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use InvalidArgumentException;

/**
 * Turns a claim into the exact quantity that will be locked (§13.4).
 *
 * ── Why the purity calculation is not `fine × (claimed − actual) / claimed` ──
 *
 * §13.4's illustration rounds for readability: «500 × (995 − 985) / 1000 = 5
 * گرم». The real computation has to go through the physical object, because
 * what the parties agreed on is a *bar*, and a bar has a gross weight that does
 * not change when the assay is disputed — only the purity attributed to it does.
 *
 * So the trade's fine weight is converted back to the gross weight it implies
 * at the claimed purity (F2, CEIL — the seller must have handed over at least
 * this much metal), and that same gross weight is then re-valued at the purity
 * the claimant says is real (F1, FLOOR — never credit gold that is not there):
 *
 *     gross        = ceil(declared_fine × 10000 / claimed_purity)
 *     actual_fine  = floor(gross × actual_purity / 10000)
 *     shortfall    = declared_fine − actual_fine
 *
 * For the documented case — 500.000 g fine claimed at عیار ۹۹۵, actually ۹۸۵ —
 * that gives 502,513 mg gross, 494,975 mg actual, and a shortfall of 5,025 mg,
 * worth 394,362,000 rial at 78,480,000 per fine gram. The rounded 5,000 mg in
 * the prose understates the claim by 25 mg; the locked figure must be the exact
 * one, because it is money taken out of a member's hands.
 */
final class DisputedAmountCalculator
{
    private function __construct() {}

    /**
     * @param  int  $declaredFineMg  fine weight the trade was booked at
     * @param  int  $claimedPurityX10k  purity the trade declared, ×10,000
     * @param  int  $actualPurityX10k  purity the claimant says is real, ×10,000
     * @param  int  $pricePerFineGram  rial per fine gram, for the rial equivalent
     */
    public static function forPurityClaim(
        int $declaredFineMg,
        int $claimedPurityX10k,
        int $actualPurityX10k,
        int $pricePerFineGram,
    ): DisputedAmount {
        if ($declaredFineMg <= 0) {
            throw new InvalidArgumentException('A purity claim needs the trade fine weight');
        }

        if ($actualPurityX10k >= $claimedPurityX10k) {
            throw new InvalidArgumentException(
                'A purity claim must allege a purity lower than the declared one'
            );
        }

        $claimed = Purity::fromScaled($claimedPurityX10k);
        $actual = Purity::fromScaled($actualPurityX10k);

        $declaredFine = FineWeight::fromMilligrams($declaredFineMg);

        // F2 — the gross weight the declared fine weight implies, rounded up.
        $gross = $declaredFine->requiredGrossAt($claimed);

        // F1 — what that same physical weight is really worth, rounded down.
        $actualFine = FineWeight::calculate($gross, $actual);

        $shortfall = $declaredFine->minus($actualFine);

        return DisputedAmount::ofGold(
            fineMg: $shortfall->milligrams,
            rial: PricePerFineGram::fromRial($pricePerFineGram)->valueOf($shortfall)->amount,
            basis: sprintf(
                'purity %s→%s on %s g gross: shortfall %s g',
                $claimed->toPpt(),
                $actual->toPpt(),
                $gross->grams(),
                $shortfall->grams(),
            ),
        );
    }

    /**
     * A weight claim: the metal weighed less than the trade said.
     *
     * Purity is not in question, so the shortfall is the plain difference.
     */
    public static function forWeightClaim(
        int $declaredFineMg,
        int $actualFineMg,
        int $pricePerFineGram,
    ): DisputedAmount {
        if ($actualFineMg >= $declaredFineMg) {
            throw new InvalidArgumentException(
                'A weight claim must allege less fine weight than was declared'
            );
        }

        $shortfall = FineWeight::fromMilligrams($declaredFineMg - $actualFineMg);

        return DisputedAmount::ofGold(
            fineMg: $shortfall->milligrams,
            rial: PricePerFineGram::fromRial($pricePerFineGram)->valueOf($shortfall)->amount,
            basis: sprintf('declared %d mg, received %d mg', $declaredFineMg, $actualFineMg),
        );
    }

    /** A money claim: the claimant states the rial figure directly. */
    public static function forRialClaim(int $rial): DisputedAmount
    {
        if ($rial <= 0) {
            throw new InvalidArgumentException('A rial claim must be positive');
        }

        return DisputedAmount::ofRial($rial, 'claimed rial amount');
    }

    /** The rial equivalent of a fine weight, for display and for the hold. */
    public static function valueOf(int $fineMg, int $pricePerFineGram): int
    {
        return PricePerFineGram::fromRial($pricePerFineGram)
            ->valueOf(FineWeight::fromMilligrams($fineMg))
            ->amount;
    }

    /** Exposed for callers that need the implied gross weight for a timeline note. */
    public static function grossImpliedBy(int $fineMg, int $purityX10k): Weight
    {
        return FineWeight::fromMilligrams($fineMg)
            ->requiredGrossAt(Purity::fromScaled($purityX10k));
    }
}
