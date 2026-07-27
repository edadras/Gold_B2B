<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MarketBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * Pricing\Events\PriceTickAccepted → `reference_price.updated` on the public
 * `reference-price` channel (§3.2 «قیمت مرجع و اونس/ارز»).
 *
 * A cross-source outlier is dropped rather than published. The tick was
 * accepted — Pricing keeps it, and §7.4's smoothing will fold it in — but a
 * value flagged as disagreeing with every other source is exactly the number
 * that should not be on a public price ticker for the 200ms before it is
 * corrected.
 *
 * The `sourceId` is never published: which vendor the platform reads is
 * commercially sensitive operational detail, not market data.
 */
final class BroadcastReferencePrice
{
    public function __construct(private readonly MarketBroadcaster $market) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        $priceType = $shape->string('priceType');
        $value = $shape->int('value');

        if ($priceType === null || $value === null) {
            return;
        }

        if ($shape->bool('isCrossSourceOutlier') === true) {
            return;
        }

        $this->market->referencePrice(
            priceType: $priceType,
            valueRial: $value,
            effectiveValueRial: $shape->int('effectiveValue'),
            observedAt: $shape->string('observedAt'),
        );
    }
}
