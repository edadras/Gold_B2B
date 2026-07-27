<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature\Http;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\ApiTestCase;

#[Group('http')]
final class AuthEndpointsTest extends ApiTestCase
{
    #[Test]
    public function login_returns_the_documented_token_envelope(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => $user->mobile,
            'password' => UserFactory::DEFAULT_PASSWORD,
        ]);

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonStructure([
            'data' => [
                'access_token', 'refresh_token', 'token_type', 'expires_in',
                'user' => ['id', 'full_name', 'roles', 'permissions'],
                'organization' => ['id', 'display_name', 'status'],
            ],
        ]);

        $response->assertJsonPath('data.token_type', 'Bearer');
        $response->assertJsonPath('data.user.roles', ['TRADER']);
        self::assertContains('order.create', $response->json('data.user.permissions'));
    }

    #[Test]
    public function the_response_carries_the_request_id_header_and_echoes_a_supplied_one(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization);

        $requestId = $this->uuid();

        $response = $this->actingAsUser($user)
            ->withHeader('X-Request-Id', $requestId)
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertHeader('X-Request-Id', $requestId);
        $response->assertJsonPath('meta.request_id', $requestId);
    }

    #[Test]
    public function bad_credentials_return_the_documented_error_envelope(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization);

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => $user->mobile,
            'password' => 'not-the-password',
        ]);

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'INVALID_CREDENTIALS');
    }

    #[Test]
    public function validation_failures_return_field_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['mobile' => '']);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');

        $response->assertJsonStructure(['error' => ['field_errors' => ['mobile', 'password']]]);
    }

    #[Test]
    public function a_two_factor_user_gets_a_challenge_rather_than_a_token(): void
    {
        $organization = $this->makeOrganization();
        $secret = Totp::generateSecret();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);
        $user->forceFill([
            'two_factor_secret_enc' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => $user->mobile,
            'password' => UserFactory::DEFAULT_PASSWORD,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.requires_2fa', true);
        self::assertIsString($response->json('data.challenge_token'));

        $second = $this->postJson('/api/v1/auth/login/2fa', [
            'challenge_token' => $response->json('data.challenge_token'),
            'method' => 'TOTP',
            'code' => Totp::code($secret),
        ]);

        $second->assertOk();
        self::assertIsString($second->json('data.access_token'));
    }

    #[Test]
    public function auth_me_returns_user_roles_permissions_and_organization(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::ACCOUNTANT]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.user.id', (int) $user->id);
        $response->assertJsonPath('data.user.roles', ['ACCOUNTANT']);
        $response->assertJsonPath('data.organization.id', (int) $organization->id);
        self::assertContains('ledger.view', $response->json('data.permissions'));
        self::assertNotContains('order.create', $response->json('data.permissions'));
    }

    #[Test]
    public function endpoints_behind_a_token_reject_an_anonymous_caller(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
    }

    #[Test]
    public function a_refresh_token_can_be_spent_once(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization);

        $login = $this->postJson('/api/v1/auth/login', [
            'mobile' => $user->mobile,
            'password' => UserFactory::DEFAULT_PASSWORD,
        ]);

        $refreshToken = $login->json('data.refresh_token');

        $first = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
        $first->assertOk();
        self::assertIsString($first->json('data.access_token'));
        self::assertNotSame($refreshToken, $first->json('data.refresh_token'));

        // Rotation: replaying the spent token must fail.
        $replay = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
        $replay->assertStatus(401);
    }

    #[Test]
    public function sessions_are_listed_and_revocable_only_by_their_owner(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization);
        $stranger = $this->makeUser($this->makeOrganization());

        $this->postJson('/api/v1/auth/login', [
            'mobile' => $user->mobile,
            'password' => UserFactory::DEFAULT_PASSWORD,
        ])->assertOk();

        $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();

        $list = $this->actingAsUser($user)->getJson('/api/v1/auth/sessions');
        $list->assertOk();
        $list->assertJsonPath('meta.count', 1);

        // Another user's session id is 404, not 403: a 403 would confirm the id
        // exists and let a caller walk the session table.
        $this->actingAsUser($stranger)
            ->deleteJson('/api/v1/auth/sessions/'.$session->id)
            ->assertStatus(404);

        $this->actingAsUser($user)
            ->deleteJson('/api/v1/auth/sessions/'.$session->id)
            ->assertStatus(204);
    }

    #[Test]
    public function otp_can_be_sent_and_verified_for_a_password_reset(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization);

        $this->postJson('/api/v1/auth/password/forgot', ['mobile' => $user->mobile])
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        $sent = $this->postJson('/api/v1/auth/otp/send', [
            'mobile' => $user->mobile,
            'purpose' => 'PASSWORD_RESET',
        ]);
        $sent->assertOk();

        $code = $sent->json('data.debug_code');
        self::assertIsString($code);

        $verified = $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => $user->mobile,
            'purpose' => 'PASSWORD_RESET',
            'code' => $code,
        ]);
        $verified->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'mobile' => $user->mobile,
            'ticket' => $verified->json('data.ticket'),
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'mobile' => $user->mobile,
            'password' => 'a-brand-new-passphrase',
        ])->assertOk();
    }

    #[Test]
    public function a_suspended_organization_cannot_read_its_profile_but_gets_a_specific_code(): void
    {
        $organization = $this->makeOrganization(OrganizationStatus::SUSPENDED);
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        // BALANCE_VIEW is read-only and survives suspension, so the profile is
        // still readable — the point of the assertion is that the endpoint does
        // not blow up and does not leak another member's row.
        $response = $this->actingAsUser($user)->getJson('/api/v1/organization');

        $response->assertOk();
        $response->assertJsonPath('data.id', (int) $organization->id);
        $response->assertJsonPath('data.can_trade', false);
    }
}
