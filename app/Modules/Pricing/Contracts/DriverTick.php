<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\Domain\PriceType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What a driver returns: the value plus the timestamp the feed claims for it.
 */
final readonly class DriverTick
{
    public function __construct(
        public PriceType $priceType,
        public int $value,
        public int $scale,
        public CarbonImmutable $observedAt,
    ) {}

    public static function make(PriceType $type, int $value, ?CarbonInterface $observedAt = null, ?int $scale = null): self
    {
        return new self(
            $type,
            $value,
            $scale ?? $type->defaultScale(),
            $observedAt !== null ? CarbonImmutable::instance($observedAt) : CarbonImmutable::now(),
        );
    }

    public function toIncomingTick(int $sourceId): IncomingTick
    {
        return new IncomingTick(
            $sourceId,
            $this->priceType,
            $this->value,
            $this->scale,
            $this->observedAt,
            CarbonImmutable::now(),
        );
    }
}
