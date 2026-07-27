<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use JsonSerializable;

/** One held lot — §15.6 «موجودی lotها» and «ارزش روز موجودی». */
final readonly class InventoryRow implements JsonSerializable
{
    public function __construct(
        public string $lotCode,
        public int $grossMg,
        public int $fineMg,
        public int $purityX10k,
        public string $status,
        public string $location,
        public int $bookValueRial = 0,
        public int $marketValueRial = 0,
        public ?string $acquiredOn = null,
    ) {}

    public function unrealizedRial(): int
    {
        return $this->marketValueRial - $this->bookValueRial;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'lot_code' => $this->lotCode,
            'gross_mg' => $this->grossMg,
            'fine_mg' => $this->fineMg,
            'purity' => $this->purityX10k,
            'status' => $this->status,
            'location' => $this->location,
            'book_value_rial' => $this->bookValueRial,
            'market_value_rial' => $this->marketValueRial,
            'unrealized_rial' => $this->unrealizedRial(),
            'acquired_on' => $this->acquiredOn,
        ];
    }
}
