<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles\Malformed;

/**
 * A PriceTickAccepted with a null price.
 *
 * Same class basename as the real event, deliberately wrong contents. Feeds
 * MalformedDomainEventTest: the listeners must degrade to "broadcast nothing"
 * rather than throw, because an exception inside a listener surfaces as a 500
 * on the request that wrote the trade.
 */
final readonly class PriceTickAccepted
{
    public function __construct(
        public string $priceType,
        public ?int $value = null,
    ) {}
}
