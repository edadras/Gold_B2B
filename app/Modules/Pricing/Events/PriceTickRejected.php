<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

/** Fired after commit for a tick stored with is_accepted = false. */
final readonly class PriceTickRejected
{
    public function __construct(
        public int $tickId,
        public int $sourceId,
        public string $priceType,
        public int $value,
        public string $reason,
        public bool $needsManualConfirmation,
    ) {}
}
