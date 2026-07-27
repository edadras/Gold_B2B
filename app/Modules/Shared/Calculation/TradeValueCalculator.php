<?php

declare(strict_types=1);

namespace App\Modules\Shared\Calculation;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * The single place where a trade is priced.
 *
 * Principle 3 of the architecture: the trader panel, the accounting module, the
 * settlement engine and every report must reach the same number, so they all
 * call this. Duplicating any of this arithmetic elsewhere is a defect.
 *
 * Formulas F1, F5, F8, F9 — docs/11-appendix/01-formulas.md.
 */
final class TradeValueCalculator
{
    /**
     * Price a trade of a known fine weight.
     *
     * Note the ordering: gross is computed, fees and taxes are computed from
     * gross, and the net figures are then derived by ADDITION and SUBTRACTION —
     * never by independent rounding. That is what guarantees the books balance.
     */
    public function value(
        FineWeight $fineWeight,
        PricePerFineGram $price,
        FeeTerms $buyerFee,
        FeeTerms $sellerFee,
        TaxTerms $tax = new TaxTerms(),
    ): TradeValuation {
        $gross = $price->valueOf($fineWeight);

        $buyerFeeAmount = $buyerFee->applyTo($gross);
        $sellerFeeAmount = $sellerFee->applyTo($gross);

        $buyerTax = $tax->applyTo($gross, $buyerFeeAmount);
        $sellerTax = $tax->applyTo($gross, $sellerFeeAmount);

        $buyerNet = $gross->plus($buyerFeeAmount)->plus($buyerTax);
        $sellerNet = $gross->minus($sellerFeeAmount)->minus($sellerTax);

        return new TradeValuation(
            fineWeight: $fineWeight,
            pricePerFineGram: $price,
            grossAmount: $gross,
            buyerFee: $buyerFeeAmount,
            sellerFee: $sellerFeeAmount,
            buyerTax: $buyerTax,
            sellerTax: $sellerTax,
            buyerNet: $buyerNet,
            sellerNet: $sellerNet,
        );
    }

    /**
     * Price a trade described by gross weight and purity, deriving fine weight
     * first (F1).
     */
    public function valueOfGross(
        Weight $gross,
        Purity $purity,
        PricePerFineGram $price,
        FeeTerms $buyerFee,
        FeeTerms $sellerFee,
        TaxTerms $tax = new TaxTerms(),
    ): TradeValuation {
        return $this->value(
            FineWeight::calculate($gross, $purity),
            $price,
            $buyerFee,
            $sellerFee,
            $tax,
        );
    }

    /**
     * F10 — how much rial a buyer must have reserved before an order is
     * accepted. For a market order pass the worst acceptable price.
     */
    public function buyerRequirement(
        FineWeight $quantity,
        PricePerFineGram $price,
        FeeTerms $buyerFee,
        TaxTerms $tax = new TaxTerms(),
    ): Rial {
        $gross = $price->valueOf($quantity);
        $fee = $buyerFee->applyTo($gross);
        $taxAmount = $tax->applyTo($gross, $fee);

        return $gross->plus($fee)->plus($taxAmount);
    }

    /**
     * F11 — a seller reserves exactly the fine weight being sold. The seller's
     * fee is taken from rial at settlement, not from gold.
     */
    public function sellerRequirement(FineWeight $quantity): FineWeight
    {
        return $quantity;
    }
}
