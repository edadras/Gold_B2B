<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\Domain\PriceType;

/**
 * Pluggable feed adapter used by `pricing:fetch-reference`.
 *
 * Implementations that talk to a real vendor live outside this module and are
 * registered on PriceDriverRegistry; nothing in this repository performs an
 * outbound HTTP call.
 */
interface PriceDriverInterface
{
    /** Stable identifier stored on price_sources.driver. */
    public function code(): string;

    public function supports(PriceType $type): bool;

    /**
     * Return the latest observation, or null when the feed has nothing to give.
     * Implementations must not throw for ordinary feed unavailability.
     */
    public function fetch(PriceType $type): ?DriverTick;
}
