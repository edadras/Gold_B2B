<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

/** A higher-priority source stopped being usable and we fell back. */
final readonly class PriceSourceDegraded
{
    public function __construct(
        public int $sourceId,
        public string $sourceCode,
        public string $priceType,
        public string $previousStatus,
        public string $reason,
    ) {}
}
