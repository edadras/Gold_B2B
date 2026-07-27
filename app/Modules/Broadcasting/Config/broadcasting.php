<?php

declare(strict_types=1);

/*
 * Merged into config('goldb2b.broadcasting.*') by BroadcastingServiceProvider.
 *
 * The per-event ceilings are NOT here: they live on
 * Broadcasting\Domain\BroadcastEventName, because "may this event be dropped?"
 * is a correctness question (a dropped fill is a bug, a dropped depth frame is
 * not) and correctness does not belong in a config file an operator can edit
 * at 3am. What is tunable here is the aggregation window and the plumbing.
 */

return [

    /*
     | The coalescing window of §3.5. At most one frame of an aggregatable
     | event per instrument per window, which alone caps depth.updated at
     | 1000/window_ms = 10/sec; the per-second ceiling on BroadcastEventName
     | then trims quote.updated further, to 5.
     */
    'aggregation_window_ms' => (int) env('BROADCAST_AGGREGATION_WINDOW_MS', 100),

    /* Redis connection backing the throttle. */
    'throttle_connection' => env('BROADCAST_THROTTLE_REDIS_CONNECTION', 'default'),

    /* Key namespace, so an operator can see and drop them with one pattern. */
    'throttle_prefix' => env('BROADCAST_THROTTLE_PREFIX', 'goldb2b:bcast:throttle'),

    /*
     | How long a resolved instrument id -> code mapping is cached. Instrument
     | codes are immutable in practice (uq_code, and a rename would break every
     | client subscription), so this is about query volume, not freshness.
     */
    'instrument_cache_ttl' => (int) env('BROADCAST_INSTRUMENT_CACHE_TTL', 300),

    /*
     | Static id => code map. Normally empty: DatabaseInstrumentSymbols reads
     | the instruments table. Useful for a deployment slice that runs
     | Broadcasting without Trading's schema, and for tests.
     |
     | @var array<int, string>
     */
    'instrument_symbols' => [],

    /*
     | Connection whose key/secret sign the channel authorisation response.
     | Must be the same app the socket server serves, or every private
     | subscription is rejected by Reverb with a signature mismatch.
     */
    'auth_connection' => env('BROADCAST_AUTH_CONNECTION', 'reverb'),

];
