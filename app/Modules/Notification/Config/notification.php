<?php

declare(strict_types=1);

use App\Modules\Notification\Infrastructure\Channels\InAppChannel;
use App\Modules\Notification\Infrastructure\Channels\LogChannel;

/*
 * Notification module defaults, merged into `goldb2b.notification` by the
 * module provider.
 */
return [
    /*
     * Channel drivers. Everything except In-App points at LogChannel locally:
     * no real provider is integrated, so a test run cannot send a member an SMS
     * or bill anyone for it.
     *
     * Production swaps the values for real implementations of
     * Contracts\NotificationChannel — e.g.
     *   'SMS'  => App\Modules\Notification\Infrastructure\Channels\KavenegarChannel::class,
     *   'PUSH' => App\Modules\Notification\Infrastructure\Channels\FcmChannel::class,
     * and nothing else in the module changes: the six dispatch rules live in
     * NotificationDispatcher, above this seam.
     */
    'drivers' => [
        'IN_APP' => InAppChannel::class,
        'PUSH' => LogChannel::class,
        'SMS' => LogChannel::class,
        'EMAIL' => LogChannel::class,
        'WEBHOOK' => LogChannel::class,
    ],

    'log_channel' => env('NOTIFICATION_LOG_CHANNEL', 'stack'),

    // §15.4 rule 3: more than this many same-code notifications inside the
    // window collapse into one aggregate.
    'batch_threshold' => 5,
    'batch_window_minutes' => 5,

    /*
     * §15.4 rule 4 is "one event, one notification". The window bounds that
     * claim in time: without it, a legitimate reminder about the same
     * settlement tomorrow would be suppressed as a duplicate forever.
     */
    'dedup_window_hours' => 24,
];
