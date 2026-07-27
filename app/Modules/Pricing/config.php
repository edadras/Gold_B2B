<?php

declare(strict_types=1);

/*
 * Pricing defaults merged into config('goldb2b.pricing') by PricingServiceProvider.
 *
 * Anything already present in config/goldb2b.php wins, so the platform-level
 * file stays the single place an operator overrides these. Keeping the
 * module-only knobs here avoids editing a shared file for values nobody outside
 * Pricing reads.
 */
return [
    // Filter 3 of §7.4: disagreement with the median of peer sources.
    'cross_source_deviation_bps' => 200,

    // §7.4: no price at all for longer than this ⇒ signal a market halt.
    'no_price_halt_seconds' => 300,

    // §7.9: minimum gap between two notifications for the same alert.
    'alert_throttle_seconds' => 300,

    // Instrument the console command computes a reference price for by default.
    'default_instrument_id' => 1,
];
