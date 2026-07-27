<?php

declare(strict_types=1);

namespace App\Modules\Web\Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Web\Application\PanelSessionService;
use App\Modules\Web\Tests\WebTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The token-for-session exchange that stands in for a panel-local login.
 */
#[Group('web-panel')]
final class PanelSessionTest extends WebTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_valid_access_token_opens_a_browser_session(): void
    {
        $user = $this->member();
        $token = $user->createToken('web', ['*'])->plainTextToken;

        $response = $this->postJson('/app/session', ['access_token' => $token]);

        $response->assertCreated()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.organization.id', $user->organization_id);

        $this->assertAuthenticatedAs($user);

        // And the panel is now reachable with nothing but the session cookie.
        $this->get('/app/terminal')->assertOk();
    }

    #[Test]
    public function the_bearer_token_is_kept_in_the_session_and_handed_back_to_the_client(): void
    {
        $user = $this->member();
        $token = $user->createToken('web', ['*'])->plainTextToken;

        $this->postJson('/app/session', ['access_token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.api.token', $token);

        // Held server-side rather than in web storage, where any script on the
        // page could read it.
        $this->assertSame($token, session(PanelSessionService::TOKEN_KEY));
    }

    #[Test]
    public function an_unknown_token_is_refused_without_saying_why(): void
    {
        $this->postJson('/app/session', ['access_token' => str_repeat('a', 48)])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_INVALID')
            ->assertJsonPath('error.message', 'توکن نامعتبر یا منقضی است.');

        $this->assertGuest();
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $user = $this->member();
        $token = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->postJson('/app/session', ['access_token' => $token])
            ->assertStatus(401);

        $this->assertGuest();
    }

    #[Test]
    public function the_token_field_is_required(): void
    {
        $this->postJson('/app/session', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function signing_out_closes_the_session_and_locks_the_panel_again(): void
    {
        $user = $this->member();
        $token = $user->createToken('web', ['*'])->plainTextToken;

        $this->postJson('/app/session', ['access_token' => $token])->assertCreated();
        $this->assertAuthenticatedAs($user);

        $this->deleteJson('/app/session')->assertNoContent();

        $this->assertGuest();
        $this->get('/app/terminal')->assertRedirect('/app/login');
    }

    #[Test]
    public function opening_a_session_rotates_the_session_id_against_fixation(): void
    {
        $user = $this->member();
        $token = $user->createToken('web', ['*'])->plainTextToken;

        $this->get('/app/login')->assertOk();
        $before = session()->getId();

        $this->postJson('/app/session', ['access_token' => $token])->assertCreated();

        self::assertNotSame($before, session()->getId());
    }

    private function member(): User
    {
        $organization = Organization::factory()->active()->create();

        return User::factory()->forOrganization($organization)->create();
    }
}
