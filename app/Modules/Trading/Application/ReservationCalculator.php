<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Trading\Domain\Side;

/**
 * F10 and F11 — how much has to be locked before an order may rest in the book.
 *
 * A buyer reserves at its OWN price, not at the price it will eventually
 * execute at, because at reservation time that price is unknown (worked example
 * 1, step 2). Any surplus is released the moment the fill prices it exactly.
 *
 * A seller reserves exactly the fine weight of the order: the seller's fee is
 * taken from rial at settlement, never from gold (F11).
 */
final readonly class ReservationCalculator
{
    public function __construct(
        private TradeValueCalculator $calculator,
        private FeeSchedule $fees,
    ) {}

    /** F10 — rial a buyer must lock for a limit order at $price. */
    public function buyerRequirement(FineWeight $quantity, PricePerFineGram $price): Rial
    {
        return $this->calculator->buyerRequirement(
            $quantity,
            $price,
            $this->fees->takerTerms(),
            $this->fees->taxTerms(),
        );
    }

    /**
     * F10, MARKET variant — reserve against the worst price the order would
     * accept, so a fill anywhere inside the slippage band is always covered.
     */
    public function buyerRequirementAtWorstPrice(
        FineWeight $quantity,
        PricePerFineGram $bestAsk,
        int $maxSlippageBps,
    ): Rial {
        return $this->buyerRequirement($quantity, $this->worstPriceForBuyer($bestAsk, $maxSlippageBps));
    }

    public function worstPriceForBuyer(PricePerFineGram $bestAsk, int $maxSlippageBps): PricePerFineGram
    {
        return $bestAsk->worseForBuyer($maxSlippageBps);
    }

    /** F11 — a seller locks the fine weight itself, nothing more. */
    public function sellerRequirement(FineWeight $quantity): FineWeight
    {
        return $this->calculator->sellerRequirement($quantity);
    }

    /**
     * What one executed fill actually claims from a buyer's reservation:
     * the gross at the execution price plus the taker fee on it.
     *
     * Note this uses the taker rate even when the buyer was the maker. It is a
     * deliberate over-estimate of the claim, never an under-estimate, so the
     * reservation can always cover the settlement; the trade record carries the
     * real, role-correct fee and the difference comes back as surplus.
     */
    public function buyerClaimForFill(FineWeight $quantity, PricePerFineGram $executionPrice): Rial
    {
        return $this->buyerRequirement($quantity, $executionPrice);
    }

    /** Amount to lock, in the asset the given side pays in. */
    public function amountFor(Side $side, FineWeight $quantity, PricePerFineGram $price): int
    {
        return $side->isBuy()
            ? $this->buyerRequirement($quantity, $price)->amount
            : $this->sellerRequirement($quantity)->milligrams;
    }
}
