<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\RejectionReason;

/**
 * What PriceIngestionService made of one observation. Rejected ticks still have
 * a row and an id — §7.4 requires the refusal to be auditable.
 */
final readonly class IngestionResult
{
    public function __construct(
        public int $tickId,
        public bool $accepted,
        public int $effectiveValue,
        public ?RejectionReason $reason = null,
        public bool $isCrossSourceOutlier = false,
        public ?int $medianValue = null,
    ) {}

    public function wasRejected(): bool
    {
        return ! $this->accepted;
    }
}
