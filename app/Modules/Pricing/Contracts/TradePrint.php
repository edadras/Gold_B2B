<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A single executed trade as Pricing needs to see it. Scalars only, so Trading
 * can hand one over without leaking its models.
 */
final readonly class TradePrint
{
    public function __construct(
        public int $instrumentId,
        public int $pricePerFineGramRial,
        public int $fineWeightMg,
        public CarbonImmutable $executedAt,
        public TradeSource $source,
        public ?int $tradeId = null,
    ) {}

    public static function make(
        int $instrumentId,
        int $pricePerFineGramRial,
        int $fineWeightMg,
        CarbonInterface $executedAt,
        TradeSource $source = TradeSource::ORDER_BOOK,
        ?int $tradeId = null,
    ): self {
        return new self(
            $instrumentId,
            $pricePerFineGramRial,
            $fineWeightMg,
            CarbonImmutable::instance($executedAt),
            $source,
            $tradeId,
        );
    }
}
