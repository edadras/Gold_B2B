<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use App\Modules\Shared\Support\IntMath;
use JsonSerializable;

/**
 * The profit and loss statement of §9.4, plus the informational block below it.
 *
 * The separation is the point. §9.5 explains why both numbers are shown:
 * «معامله‌گر سود اقتصادی را می‌خواهد، حسابدار سود حسابداری را». Mixing them
 * would give the trader a number that lags the market and the accountant a
 * number that includes gains nobody has realised.
 */
final readonly class PnlFacts implements JsonSerializable
{
    /** @param array<string, int> $operatingExpenses label → rial */
    public function __construct(
        public int $salesRevenue,
        public int $costOfGoodsSold,
        public array $operatingExpenses = [],
        /** Informational only — never part of the accounting result. */
        public int $unrealizedRial = 0,
        public int $closingMarketValue = 0,
        public bool $unrealizedAvailable = false,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0);
    }

    public function grossProfit(): int
    {
        return IntMath::sub($this->salesRevenue, $this->costOfGoodsSold);
    }

    public function totalExpenses(): int
    {
        return IntMath::sum(array_values($this->operatingExpenses));
    }

    /** What the accountant reports: realised only. */
    public function netOperatingProfit(): int
    {
        return IntMath::sub($this->grossProfit(), $this->totalExpenses());
    }

    /** What the trader watches: realised plus the change in what is still held. */
    public function economicProfit(): int
    {
        return IntMath::add($this->netOperatingProfit(), $this->unrealizedRial);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'sales_revenue' => $this->salesRevenue,
            'cost_of_goods_sold' => $this->costOfGoodsSold,
            'gross_profit' => $this->grossProfit(),
            'operating_expenses' => $this->operatingExpenses,
            'total_expenses' => $this->totalExpenses(),
            'net_operating_profit' => $this->netOperatingProfit(),
            'unrealized_rial' => $this->unrealizedRial,
            'unrealized_available' => $this->unrealizedAvailable,
            'closing_market_value' => $this->closingMarketValue,
            'economic_profit' => $this->economicProfit(),
        ];
    }
}
