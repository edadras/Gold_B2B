<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Resources;

use App\Modules\Notification\Infrastructure\PushDevice;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A registered push target — `POST /devices`.
 *
 * The token itself is NEVER echoed back in full. The client already holds it,
 * so returning it buys nothing, while a token in a response body ends up in
 * proxy logs and crash reports; a short suffix is enough for a human to tell
 * two of their own devices apart.
 *
 * @mixin PushDevice
 */
final class PushDeviceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PushDevice $device */
        $device = $this->resource;

        $token = (string) $device->token;

        return [
            'id' => (int) $device->id,
            'platform' => (string) $device->platform,
            'device_name' => $device->device_name,
            'app_version' => $device->app_version,
            'token_suffix' => mb_substr($token, -6),
            'is_active' => $device->revoked_at === null,
            'last_seen_at' => Display::iso($device->last_seen_at),
            'revoked_at' => Display::iso($device->revoked_at),
        ] + $this->display($request, [
            'last_seen_at_jalali' => Display::jalali($device->last_seen_at),
        ]);
    }
}
