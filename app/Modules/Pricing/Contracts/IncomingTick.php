<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\Domain\PriceType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A raw observation handed to PriceIngestionService before any validation.
 */
final readonly class IncomingTick
{
    public function __construct(
        public int $sourceId,
        public PriceType $priceType,
        public int $value,
        public int $scale,
        public CarbonImmutable $observedAt,
        public CarbonImmutable $receivedAt,
    ) {}

    public static function make(
        int $sourceId,
        PriceType $priceType,
        int $value,
        CarbonInterface $observedAt,
        ?CarbonInterface $receivedAt = null,
        ?int $scale = null,
    ): self {
        return new self(
            $sourceId,
            $priceType,
            $value,
            $scale ?? $priceType->defaultScale(),
            CarbonImmutable::instance($observedAt),
            $receivedAt !== null ? CarbonImmutable::instance($receivedAt) : CarbonImmutable::now(),
        );
    }

    public function ageSeconds(): int
    {
        return (int) $this->observedAt->diffInSeconds($this->receivedAt, absolute: false);
    }
}
