<?php

declare(strict_types=1);

namespace App\Modules\Web\Application;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Web\Domain\PanelScreen;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Builds the JSON blob the panel shell hands to the browser on first paint.
 *
 * Everything the SPA needs before it can make its first API call lives here:
 * who the viewer is, which member they act for, the bearer token for
 * `/api/v1`, and the transport settings (WebSocket key if one is configured,
 * polling interval otherwise).
 *
 * TENANCY, enforced by construction: the organisation is read from the
 * *authenticated user's* `organization_id` and nowhere else. No request
 * parameter, header or session value can influence it, so there is no input a
 * caller could vary to see another member's data. The panel never receives an
 * organisation id it did not already belong to.
 *
 * Identity is reached only through `IdentityDirectory`, its published contract
 * (AGENT_BRIEF rule 7); the Eloquent user handed in by the auth guard is read
 * for its primary key and nothing else.
 */
final readonly class PanelBootstrap
{
    public function __construct(private IdentityDirectory $directory) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(Authenticatable $user, ?string $apiToken = null): array
    {
        $userId = (int) $user->getAuthIdentifier();
        $snapshot = $this->directory->findUser($userId);

        // The guard resolved the row a moment ago, so a null snapshot means the
        // account was deleted mid-session. Fail closed rather than render a
        // panel with no tenancy attached.
        if ($snapshot === null) {
            return [];
        }

        $organization = $this->directory->findOrganization($snapshot->organizationId);

        return [
            'user' => [
                'id' => $snapshot->id,
                'full_name' => $snapshot->fullName,
                'status' => $snapshot->status,
                'roles' => $snapshot->roles,
                'permissions' => $snapshot->permissions,
            ],
            'organization' => $organization === null ? null : [
                'id' => $organization->id,
                'display_name' => $organization->displayName,
                'status' => $organization->status,
                'type' => $organization->type,
                'can_trade' => $organization->canTrade,
                'can_settle' => $organization->canSettle,
            ],
            'api' => [
                'base_url' => '/api/v1',
                'token' => $apiToken,
            ],
            'realtime' => $this->realtime(),
            'navigation' => PanelScreen::navigation(),
            'locale' => [
                'timezone' => (string) config('goldb2b.market.timezone', 'Asia/Tehran'),
                'direction' => 'rtl',
            ],
        ];
    }

    /**
     * Transport settings for live market data.
     *
     * `websocket` is null unless a pusher-protocol broadcaster is actually
     * configured. The broadcasting default in this environment is `log`, which
     * has no socket for a browser to open, so the panel falls back to polling
     * and says so in the status bar rather than silently showing frozen prices.
     *
     * @return array<string, mixed>
     */
    private function realtime(): array
    {
        $connection = (string) config('broadcasting.default', 'log');
        $driver = (string) config("broadcasting.connections.{$connection}.driver", '');
        $key = config("broadcasting.connections.{$connection}.key");

        $usable = in_array($driver, ['reverb', 'pusher'], true) && is_string($key) && $key !== '';

        return [
            'websocket' => $usable ? [
                'key' => $key,
                'host' => config("broadcasting.connections.{$connection}.options.host"),
                'port' => (int) config("broadcasting.connections.{$connection}.options.port", 443),
                'scheme' => (string) config("broadcasting.connections.{$connection}.options.scheme", 'https'),
                'auth_endpoint' => '/api/v1/broadcasting/auth',
            ] : null,
            // Doc §1.6: anything older than 30 s is greyed out and labelled.
            'poll_interval_ms' => 2_000,
            'stale_after_ms' => 30_000,
        ];
    }
}
