<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Shared\ValueObjects\Weight;
use Carbon\CarbonImmutable;

/**
 * What a member is about to do, expressed in the smallest set of facts the
 * pre-trade checks need. Scalars only, so Trading can build one without Risk
 * knowing anything about orders.
 *
 * $settlementType is the settlement code agreed for the trade ('T0', 'T1',
 * 'ON_ACCOUNT'). It stays a string rather than an enum because the Settlement
 * module owns that vocabulary and Risk only matches it against the member's
 * allowed list (check 8 of §11.4).
 */
final readonly class TradeIntent
{
    public function __construct(
        public int $organizationId,
        public int $userId,
        public TradeSide $side,
        public int $fineWeightMg,
        public int $priceRial,
        public string $settlementType,
        public ?int $counterpartyOrgId = null,
        public ?CarbonImmutable $intendedAt = null,
    ) {}

    public static function make(
        int $organizationId,
        int $userId,
        TradeSide $side,
        FineWeight $fineWeight,
        PricePerFineGram $price,
        string $settlementType,
        ?int $counterpartyOrgId = null,
        ?CarbonImmutable $intendedAt = null,
    ): self {
        return new self(
            $organizationId,
            $userId,
            $side,
            $fineWeight->milligrams,
            $price->rial,
            $settlementType,
            $counterpartyOrgId,
            $intendedAt,
        );
    }

    public function fineWeight(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fineWeightMg);
    }

    /** F5 — notional value of the intent, floor(mg × price / 1000). */
    public function grossValue(): Rial
    {
        return Rial::fromRial(
            IntMath::mulDivFloor($this->fineWeightMg, $this->priceRial, Weight::MG_PER_GRAM)
        );
    }

    public function isOtc(): bool
    {
        return $this->counterpartyOrgId !== null;
    }

    public function at(): CarbonImmutable
    {
        return $this->intendedAt ?? CarbonImmutable::now();
    }
}
