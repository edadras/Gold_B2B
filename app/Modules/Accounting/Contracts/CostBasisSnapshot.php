<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use JsonSerializable;

/**
 * An organisation's inventory position at weighted-average cost (F14).
 *
 * `averageCostPerGram` is rial per fine GRAM, matching the formula's output
 * unit; quantity stays in milligrams like everything else.
 */
final readonly class CostBasisSnapshot implements JsonSerializable
{
    public function __construct(
        public int $organizationId,
        public int $quantityMg,
        public int $totalCostRial,
        public int $averageCostPerGram,
    ) {}

    public static function empty(int $organizationId): self
    {
        return new self($organizationId, 0, 0, 0);
    }

    public function isEmpty(): bool
    {
        return $this->quantityMg === 0;
    }

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'quantity_mg' => $this->quantityMg,
            'total_cost_rial' => $this->totalCostRial,
            'average_cost_per_gram' => $this->averageCostPerGram,
        ];
    }
}
