<?php

declare(strict_types=1);

namespace App\Modules\Trading\Listeners;

use App\Modules\Pricing\Events\NoPriceAvailable;
use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\MarketSessionService;

/**
 * No reference price for longer than the configured gap — pause every tradable
 * instrument until a source comes back (§4.8, "اگر منبع قیمت مرجع در دسترس
 * نباشد بیش از ۵ دقیقه").
 *
 * NoPriceAvailable is platform-wide rather than per-instrument (it names a
 * price *type*, not an instrument), and Pricing sets $shouldHaltMarket only
 * once the gap has actually exceeded the limit. So this halts everything that
 * is currently tradable, and only when that flag is set — a transient gap must
 * not stop the market.
 */
final readonly class PauseMarketOnMissingPrice
{
    public function __construct(
        private MarketSessionService $sessions,
        private InstrumentRepository $instruments,
    ) {}

    public function handle(NoPriceAvailable $event): void
    {
        if (! $event->shouldHaltMarket) {
            return;
        }

        foreach ($this->instruments->activeIds() as $instrumentId) {
            $this->sessions->pauseForMissingPrice($instrumentId, $event->secondsSinceLastPrice);
        }
    }
}
