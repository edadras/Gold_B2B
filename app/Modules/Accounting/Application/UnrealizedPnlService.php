<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\MarketPriceProvider;
use App\Modules\Accounting\Contracts\UnrealizedPnl;
use App\Modules\Shared\Support\IntMath;

/**
 * F17 — «سود تحقق‌نیافته».
 *
 *   unrealized = floor(qty_mg × current_market_price / 1000) − book_value
 *
 * ────────────────────────────────────────────────────────────────────────
 *  THIS SERVICE MUST NEVER WRITE TO THE LEDGER OR THE JOURNAL.
 * ────────────────────────────────────────────────────────────────────────
 *
 * That is not a stylistic preference. Unrealised gain is an opinion about a
 * price, and the price changes every few seconds; booking it would make the
 * trial balance a function of the market rather than of what happened. §9.4
 * prints the figure under «اطلاعاتی (غیر از دفتر)», and §9.5 shows it beside
 * the accounting profit precisely so the two are never confused: the trader
 * wants the economic number, the accountant wants the realised one.
 *
 * Enforcement is structural — this class has no JournalPoster, no ledger
 * contract and no models it could write through. It reads a cost basis and a
 * price, and returns a value object.
 */
final readonly class UnrealizedPnlService
{
    private const MG_PER_GRAM = 1_000;

    public function __construct(
        private CostBasisService $costBasis,
        private MarketPriceProvider $prices,
    ) {}

    /** Current mark-to-market position. */
    public function forOrganization(int $organizationId, ?int $marketPricePerGram = null): UnrealizedPnl
    {
        $price = $marketPricePerGram ?? $this->prices->currentPricePerFineGram();

        return $this->compute($organizationId, $price);
    }

    /** Mark-to-market as at the close of a given Y-m-d date. */
    public function asOf(int $organizationId, string $date): UnrealizedPnl
    {
        return $this->compute($organizationId, $this->prices->closingPricePerFineGram($date));
    }

    private function compute(int $organizationId, ?int $pricePerGram): UnrealizedPnl
    {
        $basis = $this->costBasis->snapshot($organizationId);

        if ($pricePerGram === null || $pricePerGram <= 0) {
            return UnrealizedPnl::unavailable($organizationId, $basis->quantityMg, $basis->totalCostRial);
        }

        // Book value is the carried cost. Recomputing it from the average would
        // reintroduce the rounding the average already absorbed.
        $bookValue = $basis->totalCostRial;

        $marketValue = IntMath::mulDivFloor($basis->quantityMg, $pricePerGram, self::MG_PER_GRAM);

        return new UnrealizedPnl(
            organizationId: $organizationId,
            quantityMg: $basis->quantityMg,
            bookValueRial: $bookValue,
            marketValueRial: $marketValue,
            unrealizedRial: IntMath::sub($marketValue, $bookValue),
            marketPricePerGram: $pricePerGram,
            available: true,
        );
    }
}
