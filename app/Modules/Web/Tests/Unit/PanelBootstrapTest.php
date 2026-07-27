<?php

declare(strict_types=1);

namespace App\Modules\Web\Tests\Unit;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationSnapshot;
use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Web\Application\PanelBootstrap;
use App\Modules\Web\Domain\PanelScreen;
use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bootstrap payload, in isolation from HTTP.
 *
 * The transport branch is the interesting part: the panel behaves differently
 * depending on whether a real broadcaster is configured, and getting that wrong
 * in either direction is a bug the user sees — a panel that waits silently for
 * events that never arrive, or one that polls a server that is already pushing.
 */
#[Group('web-panel')]
final class PanelBootstrapTest extends TestCase
{
    #[Test]
    public function it_falls_back_to_polling_when_the_broadcaster_has_no_socket(): void
    {
        // The default in this environment: BROADCAST_CONNECTION=log.
        config()->set('broadcasting.default', 'log');

        $payload = $this->bootstrap()->forUser($this->user());

        self::assertNull($payload['realtime']['websocket']);
        self::assertSame(2_000, $payload['realtime']['poll_interval_ms']);
    }

    #[Test]
    public function it_hands_over_socket_settings_once_a_broadcaster_is_configured(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.driver', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'local-key');
        config()->set('broadcasting.connections.reverb.options.host', 'ws.goldb2b.ir');
        config()->set('broadcasting.connections.reverb.options.port', 443);
        config()->set('broadcasting.connections.reverb.options.scheme', 'https');

        $payload = $this->bootstrap()->forUser($this->user());

        self::assertSame('local-key', $payload['realtime']['websocket']['key']);
        self::assertSame('ws.goldb2b.ir', $payload['realtime']['websocket']['host']);
        self::assertSame('/api/v1/broadcasting/auth', $payload['realtime']['websocket']['auth_endpoint']);
    }

    #[Test]
    public function a_driver_without_a_key_is_not_treated_as_usable(): void
    {
        // A half-configured reverb connection would otherwise send the panel
        // looking for a socket it cannot authenticate against.
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.driver', 'reverb');
        config()->set('broadcasting.connections.reverb.key', '');

        $payload = $this->bootstrap()->forUser($this->user());

        self::assertNull($payload['realtime']['websocket']);
    }

    #[Test]
    public function it_returns_an_empty_payload_when_the_user_vanished_mid_session(): void
    {
        $directory = $this->createMock(IdentityDirectory::class);
        $directory->method('findUser')->willReturn(null);

        self::assertSame([], (new PanelBootstrap($directory))->forUser($this->user()));
    }

    #[Test]
    public function the_navigation_covers_every_screen_the_router_serves(): void
    {
        $payload = $this->bootstrap()->forUser($this->user());

        self::assertCount(count(PanelScreen::cases()), $payload['navigation']);

        foreach ($payload['navigation'] as $item) {
            self::assertNotNull(PanelScreen::tryFrom($item['key']));
            self::assertNotSame('', $item['title']);
        }
    }

    private function bootstrap(): PanelBootstrap
    {
        $directory = $this->createMock(IdentityDirectory::class);

        $directory->method('findUser')->willReturn(
            new UserSnapshot(7, 184, null, 'حسن رضایی', 'ACTIVE', ['TRADER'], ['order.create'])
        );

        $directory->method('findOrganization')->willReturn(
            new OrganizationSnapshot(184, 'INDIVIDUAL', 'ACTIVE', 'طلافروشی کریمی', 'MEDIUM', true, true)
        );

        return new PanelBootstrap($directory);
    }

    private function user(): Authenticatable
    {
        $user = $this->createMock(Authenticatable::class);
        $user->method('getAuthIdentifier')->willReturn(7);

        return $user;
    }
}
