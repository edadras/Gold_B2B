<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use JsonSerializable;

/**
 * F17 — mark-to-market on inventory the member still holds.
 *
 * INFORMATIONAL ONLY. Nothing here has been realised, so nothing here belongs
 * in the journal or the ledger; §9.4 prints it under the heading
 * «اطلاعاتی (غیر از دفتر)» for exactly that reason. `available` is false when
 * no market price could be read — a zero unrealised gain and an unknown one are
 * very different statements and must not look alike on a screen.
 */
final readonly class UnrealizedPnl implements JsonSerializable
{
    public function __construct(
        public int $organizationId,
        public int $quantityMg,
        public int $bookValueRial,
        public int $marketValueRial,
        public int $unrealizedRial,
        public ?int $marketPricePerGram,
        public bool $available,
    ) {}

    public static function unavailable(int $organizationId, int $quantityMg, int $bookValueRial): self
    {
        return new self(
            organizationId: $organizationId,
            quantityMg: $quantityMg,
            bookValueRial: $bookValueRial,
            marketValueRial: 0,
            unrealizedRial: 0,
            marketPricePerGram: null,
            available: false,
        );
    }

    public function isGain(): bool
    {
        return $this->available && $this->unrealizedRial > 0;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'quantity_mg' => $this->quantityMg,
            'book_value_rial' => $this->bookValueRial,
            'market_value_rial' => $this->marketValueRial,
            'unrealized_rial' => $this->unrealizedRial,
            'market_price_per_gram' => $this->marketPricePerGram,
            'available' => $this->available,
            'informational_only' => true,
        ];
    }
}
