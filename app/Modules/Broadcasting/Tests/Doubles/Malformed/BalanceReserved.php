<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles\Malformed;

/**
 * A BalanceReserved missing the asset type.
 *
 * Same class basename as the real event, deliberately wrong contents. Feeds
 * MalformedDomainEventTest: the listeners must degrade to "broadcast nothing"
 * rather than throw, because an exception inside a listener surfaces as a 500
 * on the request that wrote the trade.
 */
final readonly class BalanceReserved
{
    public function __construct(
        public int $organizationId,
        public int $amount,
    ) {}
}
