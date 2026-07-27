<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use JsonSerializable;

/**
 * What a sale did to the inventory: F15 (cost of goods sold) and F16 (profit).
 *
 * The average cost is deliberately reported unchanged — selling never revalues
 * the remaining inventory, only buying does.
 */
final readonly class SaleCostResult implements JsonSerializable
{
    public function __construct(
        public int $soldQuantityMg,
        public int $costOfGoodsSold,
        public int $saleRevenue,
        public int $fees,
        public int $grossProfit,
        public int $realizedProfit,
        public int $averageCostPerGram,
        public int $remainingQuantityMg,
        public int $remainingCostRial,
    ) {}

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return [
            'sold_quantity_mg' => $this->soldQuantityMg,
            'cogs' => $this->costOfGoodsSold,
            'sale_revenue' => $this->saleRevenue,
            'fees' => $this->fees,
            'gross_profit' => $this->grossProfit,
            'realized_profit' => $this->realizedProfit,
            'average_cost_per_gram' => $this->averageCostPerGram,
            'remaining_quantity_mg' => $this->remainingQuantityMg,
            'remaining_cost_rial' => $this->remainingCostRial,
        ];
    }
}
