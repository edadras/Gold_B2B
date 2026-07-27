<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting
|--------------------------------------------------------------------------
|
| docs/05-api/03-realtime-webhooks.md part A. The wire protocol is Pusher's,
| served in production by **Laravel Reverb** at wss://ws.goldb2b.ir/app/{key}.
|
| ONE INSTALL STEP IS OUTSTANDING. Reverb is not vendored here — the package
| proxy in this environment refuses GitHub authentication, so `composer
| require laravel/reverb` fails. On a machine with package access:
|
|     composer require laravel/reverb
|     php artisan reverb:start --debug
|
| Nothing else changes: the `reverb` connection below is an ordinary
| pusher-protocol connection and is already spelled out in full, so installing
| the package and setting BROADCAST_CONNECTION=reverb is the whole switch.
| (`php artisan install:broadcasting` would rewrite this file — don't run it.)
|
| Until then the default is `log`: every broadcast is written to the log
| channel with its channels and payload, which is enough to develop against
| and is what the module's tests assert on. `null` discards them.
|
| The auth endpoint is NOT the framework's `/broadcasting/auth`. It is
| POST /api/v1/broadcasting/auth, owned by the Broadcasting module and mounted
| behind `auth:sanctum`; see app/Modules/Broadcasting/Http/routes.php.
|
*/

return [

    /*
    | `log` locally and in tests. Production sets BROADCAST_CONNECTION=reverb.
    */
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        /*
        | Reverb speaks the Pusher protocol, so the driver, the client-side
        | Echo config and the HMAC in BroadcastSigner are all Pusher's.
        |
        | REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET must be set in
        | every environment that runs a socket server. The signer refuses to
        | issue a signature when the secret is missing rather than signing with
        | an empty key, so a misconfigured deploy fails closed.
        */
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'ws.goldb2b.ir'),
                'port' => (int) env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle options for the server -> Reverb publish call.
                'timeout' => 5,
            ],
        ],

        /*
        | Pusher's hosted service. Kept as a documented fallback for a
        | deployment that cannot run its own socket server; the payloads and
        | channel names are identical.
        */
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
