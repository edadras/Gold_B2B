<?php

declare(strict_types=1);

namespace App\Modules\Trading\Contracts;

use JsonSerializable;

/**
 * One aggregated price level of the depth view.
 *
 * Carries an order count but never an identity and never the size of any
 * individual order: "هویت سفارش‌دهنده هرگز در عمق بازار نمایش داده نمی‌شود"
 * (docs/03-domain/04-trading.md §4.9).
 */
final readonly class OrderBookLevel implements JsonSerializable
{
    public function __construct(
        public int $priceRial,
        public int $quantityMg,
        public int $orderCount,
    ) {}

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return [
            'price' => $this->priceRial,
            'quantity_mg' => $this->quantityMg,
            'order_count' => $this->orderCount,
        ];
    }
}
