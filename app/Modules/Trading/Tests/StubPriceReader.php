<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Shared\ValueObjects\PricePerFineGram;

/**
 * A price reader that always answers, so a MARKET order can be sized even when
 * Pricing has ingested nothing.
 */
final class StubPriceReader implements PriceReaderInterface
{
    public function __construct(private readonly int $rial) {}

    public function referencePrice(int $instrumentId): ?PricePerFineGram
    {
        return PricePerFineGram::fromRial($this->rial);
    }

    public function bestBid(int $instrumentId): ?PricePerFineGram
    {
        return PricePerFineGram::fromRial($this->rial);
    }

    public function bestAsk(int $instrumentId): ?PricePerFineGram
    {
        return PricePerFineGram::fromRial($this->rial);
    }

    public function lastPrice(int $instrumentId): ?PricePerFineGram
    {
        return PricePerFineGram::fromRial($this->rial);
    }
}
