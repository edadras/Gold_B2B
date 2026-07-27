<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One live login, for the "active sessions" screen.
 *
 * `token_hash` is never exposed — that is the whole reason only a digest is
 * stored. `is_current` lets the client grey out the row the user is sitting on.
 *
 * @mixin UserSession
 */
final class SessionResource extends ApiResource
{
    public function __construct(mixed $resource, private readonly ?int $currentSessionId = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var UserSession $session */
        $session = $this->resource;

        return [
            'id' => (int) $session->id,
            'device_id' => $session->device_id,
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'geo_country' => $session->geo_country,
            'geo_city' => $session->geo_city,
            'two_factor_satisfied' => (bool) $session->two_factor_satisfied,
            'is_active' => $session->isActive(),
            'is_current' => $this->currentSessionId !== null && (int) $session->id === $this->currentSessionId,
            'started_at' => Display::iso($session->started_at),
            'last_seen_at' => Display::iso($session->last_seen_at),
            'expires_at' => Display::iso($session->expires_at),
            'revoked_at' => Display::iso($session->revoked_at),
        ] + $this->display($request, [
            'started_at_jalali' => Display::jalali($session->started_at),
            'last_seen_at_jalali' => Display::jalali($session->last_seen_at),
        ]);
    }
}
