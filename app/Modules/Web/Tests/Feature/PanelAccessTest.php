<?php

declare(strict_types=1);

namespace App\Modules\Web\Tests\Feature;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Web\Tests\WebTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two properties the panel must never lose: every screen is behind
 * authentication, and every payload is scoped to the viewer's own member.
 *
 * These reach for Identity's factories directly, which the architecture test
 * permits for fixtures — building a two-tenant scenario through a contract
 * would prove less and cost more.
 */
#[Group('web-panel')]
final class PanelAccessTest extends WebTestCase
{
    use RefreshDatabase;

    /** @return list<array{string}> */
    public static function panelPaths(): array
    {
        return [
            ['/app'],
            ['/app/terminal'],
            ['/app/ledger'],
            ['/app/settlements'],
            ['/app/orders'],
            ['/app/trades'],
            ['/app/lots'],
            ['/app/counterparties'],
            ['/app/reports'],
        ];
    }

    #[Test]
    public function every_panel_route_is_registered(): void
    {
        foreach (self::panelPaths() as [$path]) {
            $this->get($path)->assertRedirect('/app/login');
        }
    }

    #[Test]
    public function a_guest_is_redirected_to_the_sign_in_screen(): void
    {
        $this->get('/app/terminal')
            ->assertRedirect('/app/login');
    }

    #[Test]
    public function a_guest_asking_for_json_gets_401_rather_than_a_redirect(): void
    {
        // The SPA needs to tell "session expired" apart from "here is HTML".
        $this->getJson('/app/session')->assertStatus(401);
    }

    #[Test]
    public function the_sign_in_screen_is_public(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertSee('id="login"', false);
    }

    #[Test]
    public function an_authenticated_member_gets_the_shell_for_the_requested_screen(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->get('/app/ledger')
            ->assertOk()
            ->assertSee('data-screen="ledger"', false)
            ->assertSee('id="panel-bootstrap"', false);
    }

    #[Test]
    public function a_bare_app_url_lands_on_the_terminal(): void
    {
        $this->actingAs($this->member())
            ->get('/app')
            ->assertOk()
            ->assertSee('data-screen="terminal"', false);
    }

    #[Test]
    public function an_unknown_screen_is_a_404_not_a_silent_fallback(): void
    {
        $this->actingAs($this->member())
            ->get('/app/nonsense')
            ->assertNotFound();
    }

    #[Test]
    public function the_shell_carries_only_the_viewers_own_organization(): void
    {
        $mine = Organization::factory()->active()->create(['display_name' => 'طلافروشی الف']);
        $theirs = Organization::factory()->active()->create(['display_name' => 'طلافروشی ب']);

        $user = User::factory()->forOrganization($mine)->create();
        User::factory()->forOrganization($theirs)->create();

        $response = $this->actingAs($user)->get('/app/terminal');

        $response->assertOk();
        $response->assertSee('طلافروشی الف', false);
        $response->assertDontSee('طلافروشی ب', false);
    }

    #[Test]
    public function the_session_endpoint_is_scoped_to_the_viewers_organization(): void
    {
        $mine = Organization::factory()->active()->create(['display_name' => 'عضو من']);
        $theirs = Organization::factory()->active()->create(['display_name' => 'عضو دیگر']);

        $me = User::factory()->forOrganization($mine)->create(['full_name' => 'کاربر یک']);
        $other = User::factory()->forOrganization($theirs)->create(['full_name' => 'کاربر دو']);

        $this->actingAs($me)
            ->getJson('/app/session')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $mine->id)
            ->assertJsonPath('data.organization.display_name', 'عضو من')
            ->assertJsonPath('data.user.full_name', 'کاربر یک');

        // The same endpoint, a different session: nothing of the first member
        // may appear.
        $this->actingAs($other)
            ->getJson('/app/session')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $theirs->id)
            ->assertJsonMissing(['display_name' => 'عضو من']);
    }

    #[Test]
    public function the_bootstrap_payload_never_exposes_another_members_identifiers(): void
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()->forOrganization($organization)->create();

        $payload = $this->actingAs($user)->getJson('/app/session')->json('data');

        // Whatever else the payload grows, it is built from OrganizationSnapshot
        // and UserSnapshot, neither of which carries an encrypted identifier or
        // a blind index. Assert the shape rather than trusting review.
        self::assertSame(
            ['id', 'display_name', 'status', 'type', 'can_trade', 'can_settle'],
            array_keys($payload['organization']),
        );
        self::assertSame(
            ['id', 'full_name', 'status', 'roles', 'permissions'],
            array_keys($payload['user']),
        );
    }

    #[Test]
    public function the_payload_states_the_transport_and_the_staleness_threshold(): void
    {
        $payload = $this->actingAs($this->member())->getJson('/app/session')->json('data');

        // No reverb key is configured in this environment, so the panel must be
        // told to poll rather than silently waiting for events that never come.
        self::assertNull($payload['realtime']['websocket']);
        self::assertSame(2_000, $payload['realtime']['poll_interval_ms']);
        self::assertSame(30_000, $payload['realtime']['stale_after_ms']);
    }

    private function member(): User
    {
        $organization = Organization::factory()
            ->status(OrganizationStatus::ACTIVE)
            ->create();

        return User::factory()->forOrganization($organization)->create();
    }
}
