<?php

declare(strict_types=1);

namespace App\Modules\Trading\Contracts;

use App\Modules\Shared\Support\IntMath;
use JsonSerializable;

/**
 * The public depth of one instrument, shaped exactly like the JSON sample in
 * docs/03-domain/04-trading.md §4.9.
 *
 * Bids descend by price, asks ascend, and both sides are capped at
 * OrderBookReader::MAX_LEVELS.
 */
final readonly class OrderBookSnapshot implements JsonSerializable
{
    /**
     * @param list<OrderBookLevel> $bids
     * @param list<OrderBookLevel> $asks
     */
    public function __construct(
        public string $instrumentCode,
        public array $bids,
        public array $asks,
        public string $timestamp,
    ) {}

    public function bestBid(): ?int
    {
        return $this->bids[0]->priceRial ?? null;
    }

    public function bestAsk(): ?int
    {
        return $this->asks[0]->priceRial ?? null;
    }

    public function bestBidQuantityMg(): ?int
    {
        return $this->bids[0]->quantityMg ?? null;
    }

    public function bestAskQuantityMg(): ?int
    {
        return $this->asks[0]->quantityMg ?? null;
    }

    public function spread(): ?int
    {
        $bid = $this->bestBid();
        $ask = $this->bestAsk();

        return $bid === null || $ask === null ? null : IntMath::sub($ask, $bid);
    }

    /** Integer division on purpose: no float may appear on a price path. */
    public function midPrice(): ?int
    {
        $bid = $this->bestBid();
        $ask = $this->bestAsk();

        return $bid === null || $ask === null ? null : intdiv(IntMath::add($bid, $ask), 2);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'instrument' => $this->instrumentCode,
            'timestamp' => $this->timestamp,
            'bids' => $this->bids,
            'asks' => $this->asks,
            'spread' => $this->spread(),
            'mid_price' => $this->midPrice(),
        ];
    }
}
