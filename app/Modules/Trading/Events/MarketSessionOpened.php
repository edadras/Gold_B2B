<?php

declare(strict_types=1);

namespace App\Modules\Trading\Events;

/**
 * Continuous trading has begun for an instrument.
 *
 * Also fired when a PAUSED session resumes, in which case $resumedFromPause is
 * true and the opening auction is a re-opening auction (§4.8).
 */
final readonly class MarketSessionOpened
{
    public function __construct(
        public int $sessionId,
        public int $instrumentId,
        public string $sessionDate,
        public ?int $openingPriceRial,
        public bool $resumedFromPause,
        public string $occurredAt,
    ) {}
}
