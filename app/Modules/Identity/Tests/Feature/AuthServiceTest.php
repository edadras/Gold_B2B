<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\InvalidTwoFactorCodeException;
use App\Modules\Identity\Domain\Exceptions\TwoFactorRequiredException;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Identity\Tests\IdentityTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class AuthServiceTest extends IdentityTestCase
{
    use RefreshDatabase;

    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = $this->app->make(AuthService::class);
    }

    public function test_successful_login_issues_a_token_and_records_a_session(): void
    {
        $user = $this->activeUser();

        $result = $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD, [
            'ip' => '10.0.0.7',
            'user_agent' => 'PHPUnit',
            'device_id' => 'device-abc',
        ]);

        $this->assertNotSame('', $result['token']);

        $session = $result['session'];
        $this->assertSame((int) $user->id, (int) $session->user_id);
        $this->assertSame((int) $user->organization_id, (int) $session->organization_id);
        $this->assertSame('10.0.0.7', $session->ip_address);
        $this->assertSame(hash('sha256', $result['token']), $session->token_hash);
        $this->assertFalse($session->two_factor_satisfied);

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_id' => 'device-abc',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_accepts_any_mobile_format(): void
    {
        $user = $this->activeUser();
        $local = '0'.substr((string) $user->mobile, 2);

        $result = $this->auth->login($local, UserFactory::DEFAULT_PASSWORD);

        $this->assertSame((int) $user->id, (int) $result['user']->id);
    }

    public function test_wrong_password_increments_the_failure_counter(): void
    {
        $user = $this->activeUser();

        try {
            $this->auth->login($user->mobile, 'not-the-password-at-all');
            $this->fail('expected InvalidCredentialsException');
        } catch (InvalidCredentialsException $e) {
            $this->assertSame(AuthService::MAX_FAILED_ATTEMPTS - 1, $e->remainingAttempts);
        }

        $this->assertSame(1, (int) $user->fresh()->failed_login_count);
    }

    public function test_account_locks_after_ten_failed_attempts_for_thirty_minutes(): void
    {
        $user = $this->activeUser();

        // Attempts 1..9 are ordinary credential failures.
        for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS - 1; $attempt++) {
            try {
                $this->auth->login($user->mobile, 'wrong-password-'.$attempt);
                $this->fail("attempt {$attempt} should have failed");
            } catch (InvalidCredentialsException $e) {
                $this->assertSame(AuthService::MAX_FAILED_ATTEMPTS - $attempt, $e->remainingAttempts);
            }
        }

        $this->assertNull($user->fresh()->locked_until, 'nine failures must not lock the account');

        // The tenth failure locks.
        try {
            $this->auth->login($user->mobile, 'wrong-password-10');
            $this->fail('the tenth attempt should have locked the account');
        } catch (AccountLockedException $e) {
            $this->assertGreaterThan(0, $e->secondsRemaining);
        }

        $fresh = $user->fresh();
        $this->assertSame(AuthService::MAX_FAILED_ATTEMPTS, (int) $fresh->failed_login_count);
        $this->assertNotNull($fresh->locked_until);
        $this->assertEqualsWithDelta(
            AuthService::LOCKOUT_MINUTES * 60,
            now()->diffInSeconds($fresh->locked_until, false),
            60,
        );

        // Even the correct password is refused while the lock holds.
        $this->expectException(AccountLockedException::class);
        $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);
    }

    public function test_lock_expires_and_a_successful_login_clears_the_counter(): void
    {
        $user = $this->activeUser();

        $user->forceFill([
            'failed_login_count' => AuthService::MAX_FAILED_ATTEMPTS,
            'locked_until' => now()->subMinute(),
        ])->save();

        $result = $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);

        $this->assertNotSame('', $result['token']);
        $this->assertSame(0, (int) $user->fresh()->failed_login_count);
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_a_successful_login_resets_a_partial_failure_streak(): void
    {
        $user = $this->activeUser();

        try {
            $this->auth->login($user->mobile, 'wrong');
        } catch (InvalidCredentialsException) {
            // expected
        }

        $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);

        $this->assertSame(0, (int) $user->fresh()->failed_login_count);
    }

    public function test_unknown_mobile_is_indistinguishable_from_a_wrong_password(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->auth->login('09121111111', 'whatever-password');
    }

    public function test_non_active_user_cannot_log_in_even_with_the_right_password(): void
    {
        $user = $this->activeUser();
        $user->forceFill(['status' => UserStatus::SUSPENDED])->save();

        $this->expectException(OperationNotPermittedException::class);

        $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);
    }

    // ------------------------------------------------------------------
    // Two-factor
    // ------------------------------------------------------------------

    public function test_two_factor_is_required_when_enabled_and_no_token_is_issued_yet(): void
    {
        $secret = Totp::generateSecret();
        $user = $this->activeUser(fn (): array => [
            'two_factor_secret_enc' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        try {
            $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);
            $this->fail('expected a two-factor challenge');
        } catch (TwoFactorRequiredException $e) {
            $this->assertNotSame('', $e->challengeId);
            $this->assertSame('TOTP', $e->method);

            // Password alone must not create a session.
            $this->assertSame(0, UserSession::query()->where('user_id', $user->id)->count());

            $result = $this->auth->completeTwoFactorChallenge($e->challengeId, Totp::code($secret));

            $this->assertNotSame('', $result['token']);
            $this->assertTrue($result['session']->two_factor_satisfied);
        }
    }

    public function test_wrong_two_factor_code_is_rejected_and_counts_as_a_failure(): void
    {
        $secret = Totp::generateSecret();
        $user = $this->activeUser(fn (): array => [
            'two_factor_secret_enc' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        try {
            $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);
            $this->fail('expected a two-factor challenge');
        } catch (TwoFactorRequiredException $e) {
            try {
                $this->auth->completeTwoFactorChallenge($e->challengeId, '000000');
                $this->fail('expected the wrong code to be rejected');
            } catch (InvalidTwoFactorCodeException) {
                $this->assertSame(1, (int) $user->fresh()->failed_login_count);
            }
        }
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        $secret = Totp::generateSecret();
        $user = $this->activeUser(fn (): array => [
            'two_factor_secret_enc' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        $code = Totp::code($secret);

        $this->assertTrue($this->auth->verifyTwoFactor($user, $code));
        $this->assertFalse(
            $this->auth->verifyTwoFactor($user->fresh(), $code),
            'the same code must not verify twice inside its window',
        );
    }

    public function test_two_factor_enrolment_round_trip(): void
    {
        $user = $this->activeUser();

        $enrolment = $this->auth->beginTwoFactorEnrolment($user);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), 'unconfirmed enrolment is not enabled');

        $this->assertTrue($this->auth->confirmTwoFactorEnrolment($user, Totp::code($enrolment['secret'])));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_logout_and_bulk_revocation_close_sessions(): void
    {
        $user = $this->activeUser();

        $first = $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);
        $this->auth->logout($first['session']);
        $this->assertNotNull($first['session']->fresh()->revoked_at);

        $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);
        $this->auth->login($user->mobile, UserFactory::DEFAULT_PASSWORD);

        $revoked = $this->auth->revokeAllSessions($user, 'password_changed');

        $this->assertSame(2, $revoked);
        $this->assertSame(0, $user->tokens()->count());
    }

    /** @param  (callable(): array<string, mixed>)|null  $state */
    private function activeUser(?callable $state = null): User
    {
        $organization = Organization::factory()->active()->create();

        $factory = User::factory()->forOrganization($organization);

        if ($state !== null) {
            $factory = $factory->state($state);
        }

        /** @var User $user */
        $user = $factory->create();

        return $user;
    }
}
