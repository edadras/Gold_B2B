<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

/**
 * No source of any kind produced a price. When $shouldHaltMarket is true the
 * gap has exceeded the configured limit (5 minutes by default) and Trading is
 * expected to pause the session — Pricing signals, it does not halt (§7.4).
 */
final readonly class NoPriceAvailable
{
    public function __construct(
        public string $priceType,
        public ?int $secondsSinceLastPrice,
        public bool $shouldHaltMarket,
    ) {}
}
