<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Broadcasting\Infrastructure\BroadcastSigner;
use App\Modules\Identity\Domain\Role;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/broadcasting/auth — §3.1 steps 2-4, end to end.
 *
 * ChannelAuthorizationTest proves the decision; this proves the decision is
 * actually reached over HTTP, behind Sanctum, and that what comes back is the
 * signature the Pusher/Echo client looks for rather than the platform's
 * `data`/`meta` envelope.
 */
final class BroadcastAuthEndpointTest extends BroadcastingTestCase
{
    private const ENDPOINT = '/api/v1/broadcasting/auth';

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, [
            'socket_id' => '123.456',
            'channel_name' => 'private-org.1',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_member_gets_a_signature_for_their_own_organization(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        $channel = ChannelName::wire(ChannelName::organization($organization->id));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, [
                'socket_id' => '123.456',
                'channel_name' => $channel,
            ]);

        $response->assertOk();

        $expected = self::APP_KEY.':'.hash_hmac('sha256', '123.456:'.$channel, self::APP_SECRET);

        // Top level, not under `data` — Echo reads response.auth.
        $response->assertExactJson(['auth' => $expected]);
    }

    /**
     * The signature covers the channel name WITH its `private-` prefix. Reverb
     * recomputes it over the same string; signing the normalised `org.184`
     * would fail every subscription with what looks like a key mismatch.
     */
    #[Test]
    public function the_signature_covers_the_prefixed_channel_name(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        $wire = ChannelName::wire(ChannelName::organization($organization->id));
        $bare = ChannelName::organization($organization->id);

        $auth = $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['socket_id' => '9.9', 'channel_name' => $wire])
            ->json('auth');

        self::assertSame(
            self::APP_KEY.':'.hash_hmac('sha256', '9.9:'.$wire, self::APP_SECRET),
            $auth,
        );

        self::assertNotSame(
            self::APP_KEY.':'.hash_hmac('sha256', '9.9:'.$bare, self::APP_SECRET),
            $auth,
        );
    }

    #[Test]
    public function a_member_is_refused_another_organizations_channel(): void
    {
        $a = $this->organization();
        $b = $this->organization();
        $user = $this->member($a);

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, [
                'socket_id' => '123.456',
                'channel_name' => ChannelName::wire(ChannelName::organization($b->id)),
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CHANNEL_FORBIDDEN');
    }

    /**
     * A channel belonging to nobody must be refused identically to one
     * belonging to a real member the caller is not part of. Any difference —
     * a different code, a different status, a different message — turns this
     * endpoint into a membership oracle over the whole platform.
     */
    #[Test]
    public function a_nonexistent_organization_is_refused_identically(): void
    {
        $a = $this->organization();
        $b = $this->organization();
        $user = $this->member($a);

        $real = $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'socket_id' => '123.456',
            'channel_name' => ChannelName::wire(ChannelName::organization($b->id)),
        ]);

        $imaginary = $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'socket_id' => '123.456',
            'channel_name' => 'private-org.987654321',
        ]);

        self::assertSame($real->status(), $imaginary->status());
        self::assertSame($real->json('error.code'), $imaginary->json('error.code'));
        self::assertSame($real->json('error.message'), $imaginary->json('error.message'));
    }

    #[Test]
    public function a_member_is_refused_the_admin_channel_and_staff_is_not(): void
    {
        $member = $this->member($this->organization(), [Role::OWNER]);
        $staff = $this->staff([Role::PLATFORM_ADMIN]);

        $this->actingAs($member, 'sanctum')
            ->postJson(self::ENDPOINT, ['socket_id' => '1.1', 'channel_name' => 'private-admin.monitoring'])
            ->assertStatus(403);

        $this->actingAs($staff, 'sanctum')
            ->postJson(self::ENDPOINT, ['socket_id' => '1.1', 'channel_name' => 'private-admin.monitoring'])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    #[Test]
    public function a_public_channel_is_told_it_needs_no_authorisation(): void
    {
        $user = $this->member($this->organization());

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, ['socket_id' => '1.1', 'channel_name' => 'market.GOLD-995-T0'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CHANNEL_PUBLIC');
    }

    #[Test]
    public function a_malformed_socket_id_is_rejected_before_anything_is_signed(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, [
                'socket_id' => 'not-a-socket',
                'channel_name' => ChannelName::wire(ChannelName::organization($organization->id)),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function an_unconfigured_app_secret_fails_closed(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        config()->set('broadcasting.connections.reverb.secret', null);
        // The signer is a singleton resolved from config at construction.
        $this->app->forgetInstance(BroadcastSigner::class);

        $this->actingAs($user, 'sanctum')
            ->postJson(self::ENDPOINT, [
                'socket_id' => '1.1',
                'channel_name' => ChannelName::wire(ChannelName::organization($organization->id)),
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'BROADCAST_UNAVAILABLE');
    }
}
