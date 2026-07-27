<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Shared\ValueObjects\PricePerFineGram;

/** Deterministic PriceReaderInterface for tests that must not touch the DB. */
final class FixedPriceReader implements PriceReaderInterface
{
    public function __construct(
        private ?int $reference = null,
        private ?int $bid = null,
        private ?int $ask = null,
        private ?int $last = null,
    ) {}

    public function referencePrice(int $instrumentId): ?PricePerFineGram
    {
        return $this->wrap($this->reference);
    }

    public function bestBid(int $instrumentId): ?PricePerFineGram
    {
        return $this->wrap($this->bid);
    }

    public function bestAsk(int $instrumentId): ?PricePerFineGram
    {
        return $this->wrap($this->ask);
    }

    public function lastPrice(int $instrumentId): ?PricePerFineGram
    {
        return $this->wrap($this->last);
    }

    private function wrap(?int $value): ?PricePerFineGram
    {
        return $value === null ? null : PricePerFineGram::fromRial($value);
    }
}
