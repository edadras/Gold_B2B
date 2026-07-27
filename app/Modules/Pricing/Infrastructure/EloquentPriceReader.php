<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Modules\Pricing\Contracts\PriceReaderInterface;
use App\Modules\Pricing\Infrastructure\Models\MarketQuote;
use App\Modules\Pricing\Infrastructure\Models\ReferencePrice;
use App\Modules\Shared\ValueObjects\PricePerFineGram;

/**
 * The single adapter between Pricing's tables and everyone else's read needs.
 */
final class EloquentPriceReader implements PriceReaderInterface
{
    public function referencePrice(int $instrumentId): ?PricePerFineGram
    {
        /** @var ReferencePrice|null $row */
        $row = ReferencePrice::query()
            ->where('instrument_id', $instrumentId)
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : PricePerFineGram::fromRial($row->fine_gram_rial);
    }

    public function bestBid(int $instrumentId): ?PricePerFineGram
    {
        return $this->fromQuote($instrumentId, 'best_bid');
    }

    public function bestAsk(int $instrumentId): ?PricePerFineGram
    {
        return $this->fromQuote($instrumentId, 'best_ask');
    }

    public function lastPrice(int $instrumentId): ?PricePerFineGram
    {
        return $this->fromQuote($instrumentId, 'last_price');
    }

    private function fromQuote(int $instrumentId, string $column): ?PricePerFineGram
    {
        /** @var MarketQuote|null $quote */
        $quote = MarketQuote::query()->find($instrumentId);

        if ($quote === null || $quote->{$column} === null) {
            return null;
        }

        return PricePerFineGram::fromRial((int) $quote->{$column});
    }
}
