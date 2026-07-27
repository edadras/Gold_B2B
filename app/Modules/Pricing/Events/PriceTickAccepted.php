<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

/** Fired after commit once a tick has cleared every filter in §7.4. */
final readonly class PriceTickAccepted
{
    public function __construct(
        public int $tickId,
        public int $sourceId,
        public string $priceType,
        public int $value,
        public int $effectiveValue,
        public bool $isCrossSourceOutlier,
        public string $observedAt,
    ) {}
}
