<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application\Results;

use App\Modules\Trading\Infrastructure\Models\Trade;

/**
 * What one opening (or re-opening) auction produced.
 *
 * Stays inside the module — it carries Eloquent models, so it is a service
 * return type, not a contract. The scalars on it are what the
 * OpeningAuctionCompleted event publishes.
 */
final readonly class AuctionResult
{
    /**
     * @param  list<Trade>  $trades
     * @param  ?int  $clearingPriceRial  null when nothing crossed, in which case
     *                                   there is no price the auction can defend
     */
    public function __construct(
        public ?int $clearingPriceRial,
        public int $matchedVolumeMg,
        public int $orderCount,
        public array $trades = [],
    ) {}

    public static function empty(): self
    {
        return new self(null, 0, 0, []);
    }

    public function executed(): bool
    {
        return $this->trades !== [];
    }

    public function tradeCount(): int
    {
        return count($this->trades);
    }
}
