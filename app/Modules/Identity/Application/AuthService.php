<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\InvalidTwoFactorCodeException;
use App\Modules\Identity\Domain\Exceptions\TwoFactorRequiredException;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Domain\Validators\MobileNormalizer;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserDevice;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mobile + password login, TOTP second factor, Sanctum token issue and session
 * recording — the flow of docs/02-architecture/04-security.md §4.2.
 *
 * Lockout policy: 10 consecutive failures lock the account for 30 minutes. The
 * counter lives on the users row rather than in the cache so a cache flush
 * cannot clear a lock, and so the lock survives a restart.
 */
final class AuthService
{
    public const MAX_FAILED_ATTEMPTS = 10;

    public const LOCKOUT_MINUTES = 30;

    /** Access token lifetime (§4.2 step 4). */
    public const ACCESS_TOKEN_MINUTES = 15;

    /** How long a half-finished login may sit waiting for its TOTP code. */
    public const CHALLENGE_TTL_SECONDS = 300;

    /**
     * Step 1: mobile + password.
     *
     * @param  array<string, mixed>  $context  ip, user_agent, device_id, geo_country, geo_city
     * @return array{token: string, user: User, session: UserSession, expires_at: string}
     *
     * @throws InvalidCredentialsException|AccountLockedException|TwoFactorRequiredException
     */
    public function login(string $mobile, string $password, array $context = []): array
    {
        $normalized = MobileNormalizer::normalize($mobile);

        // Unknown numbers still burn a hash comparison so response timing does
        // not distinguish "no such user" from "wrong password".
        $user = $normalized === null
            ? null
            : User::query()->where('mobile', $normalized)->first();

        if ($user === null) {
            Hash::check($password, '$2y$12$'.str_repeat('.', 53));

            throw new InvalidCredentialsException(self::MAX_FAILED_ATTEMPTS);
        }

        $this->assertNotLocked($user);

        if (! Hash::check($password, $user->getAuthPassword())) {
            $remaining = $this->recordFailedAttempt($user);

            throw new InvalidCredentialsException($remaining);
        }

        if (! $user->status->canAuthenticate()) {
            throw new OperationNotPermittedException('user_status:'.$user->status->value);
        }

        $this->clearFailedAttempts($user);

        if ($user->hasTwoFactorEnabled()) {
            throw new TwoFactorRequiredException($this->issueChallenge($user, $context));
        }

        return $this->completeLogin($user, $context, twoFactorSatisfied: false);
    }

    /**
     * Step 2: answer the TOTP challenge issued by login().
     *
     * @param  array<string, mixed>  $context
     * @return array{token: string, user: User, session: UserSession, expires_at: string}
     */
    public function completeTwoFactorChallenge(string $challengeId, string $code): array
    {
        /** @var array{user_id: int, context: array<string, mixed>}|null $challenge */
        $challenge = Cache::get($this->challengeKey($challengeId));

        if ($challenge === null) {
            throw new InvalidTwoFactorCodeException;
        }

        /** @var User $user */
        $user = User::query()->findOrFail($challenge['user_id']);

        $this->assertNotLocked($user);

        if (! $this->verifyTwoFactor($user, $code)) {
            $this->recordFailedAttempt($user);

            throw new InvalidTwoFactorCodeException;
        }

        Cache::forget($this->challengeKey($challengeId));
        $this->clearFailedAttempts($user);

        return $this->completeLogin($user, $challenge['context'], twoFactorSatisfied: true);
    }

    /**
     * Verify a TOTP code and burn its counter so the same code cannot be
     * replayed inside its 30-second window.
     */
    public function verifyTwoFactor(User $user, string $code): bool
    {
        $secret = (string) ($user->two_factor_secret_enc ?? '');

        if ($secret === '') {
            return false;
        }

        if (! Totp::verify($secret, $code)) {
            return false;
        }

        $counter = intdiv(time(), Totp::PERIOD_SECONDS);
        $lastCounter = $user->two_factor_last_counter;

        if ($lastCounter !== null && $counter <= (int) $lastCounter) {
            return false;
        }

        $user->forceFill(['two_factor_last_counter' => $counter])->save();

        return true;
    }

    /**
     * Re-authentication for a high-value action (§4.2 "Transaction Signing").
     * Does not issue a token; it only answers "is this really them right now?".
     */
    public function confirmTransaction(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new OperationNotPermittedException('two_factor_not_enrolled');
        }

        return $this->verifyTwoFactor($user, $code);
    }

    /** Begin TOTP enrolment. The secret is only confirmed once a code verifies. */
    public function beginTwoFactorEnrolment(User $user, string $issuer = 'Gold B2B'): array
    {
        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret_enc' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, $user->mobile, $issuer),
        ];
    }

    public function confirmTwoFactorEnrolment(User $user, string $code): bool
    {
        $secret = (string) ($user->two_factor_secret_enc ?? '');

        if ($secret === '' || ! Totp::verify($secret, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_counter' => intdiv(time(), Totp::PERIOD_SECONDS),
        ])->save();

        return true;
    }

    public function logout(UserSession $session, string $reason = 'user_logout'): void
    {
        DB::transaction(function () use ($session, $reason): void {
            $session->revoked_at = now();
            $session->revoked_reason = $reason;
            $session->save();
        });
    }

    /** Revoke every live session and token for a user (suspension, password change). */
    public function revokeAllSessions(User $user, string $reason): int
    {
        $user->tokens()->delete();

        return UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{token: string, user: User, session: UserSession, expires_at: string}
     */
    private function completeLogin(User $user, array $context, bool $twoFactorSatisfied): array
    {
        $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_MINUTES);

        $newAccessToken = $user->createToken(
            name: (string) ($context['device_id'] ?? 'web'),
            abilities: $user->permissionNames() === [] ? ['*'] : $user->permissionNames(),
            expiresAt: $expiresAt,
        );

        $session = DB::transaction(function () use ($user, $context, $twoFactorSatisfied, $newAccessToken, $expiresAt): UserSession {
            /** @var UserSession $session */
            $session = UserSession::query()->create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                // Store only a digest: the plaintext token never touches a
                // durable table we can be forced to hand over.
                'token_hash' => hash('sha256', $newAccessToken->plainTextToken),
                'device_id' => $context['device_id'] ?? null,
                'ip_address' => $context['ip'] ?? null,
                'user_agent' => isset($context['user_agent'])
                    ? mb_substr((string) $context['user_agent'], 0, 500)
                    : null,
                'geo_country' => $context['geo_country'] ?? null,
                'geo_city' => $context['geo_city'] ?? null,
                'two_factor_satisfied' => $twoFactorSatisfied,
                'started_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $context['ip'] ?? null,
                'failed_login_count' => 0,
                'locked_until' => null,
            ])->save();

            if (isset($context['device_id'])) {
                $this->rememberDevice($user, $context);
            }

            return $session;
        });

        return [
            'token' => $newAccessToken->plainTextToken,
            'user' => $user,
            'session' => $session,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $context */
    private function rememberDevice(User $user, array $context): void
    {
        UserDevice::query()->updateOrCreate(
            ['user_id' => $user->id, 'device_id' => (string) $context['device_id']],
            [
                'name' => $context['device_name'] ?? null,
                'platform' => $context['platform'] ?? null,
                'app_version' => $context['app_version'] ?? null,
                'last_seen_at' => now(),
                'last_ip' => $context['ip'] ?? null,
            ],
        );
    }

    /** @param  array<string, mixed>  $context */
    private function issueChallenge(User $user, array $context): string
    {
        $challengeId = (string) Str::uuid();

        Cache::put(
            $this->challengeKey($challengeId),
            ['user_id' => (int) $user->id, 'context' => $context],
            self::CHALLENGE_TTL_SECONDS,
        );

        return $challengeId;
    }

    private function challengeKey(string $challengeId): string
    {
        return 'identity:2fa-challenge:'.$challengeId;
    }

    /** @throws AccountLockedException */
    private function assertNotLocked(User $user): void
    {
        if (! $user->isLocked()) {
            return;
        }

        $lockedUntil = $user->locked_until;

        throw new AccountLockedException(
            lockedUntil: $lockedUntil->toIso8601String(),
            secondsRemaining: max(0, (int) now()->diffInSeconds($lockedUntil, false)),
        );
    }

    /**
     * @return int attempts remaining before the account locks
     *
     * @throws AccountLockedException when this attempt was the last one
     */
    private function recordFailedAttempt(User $user): int
    {
        $count = DB::transaction(function () use ($user): int {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $count = (int) $locked->failed_login_count + 1;
            $locked->failed_login_count = $count;

            if ($count >= self::MAX_FAILED_ATTEMPTS) {
                $locked->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
            }

            $locked->save();
            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return $count;
        });

        if ($count >= self::MAX_FAILED_ATTEMPTS) {
            $this->assertNotLocked($user);
        }

        return max(0, self::MAX_FAILED_ATTEMPTS - $count);
    }

    private function clearFailedAttempts(User $user): void
    {
        if ((int) $user->failed_login_count === 0 && $user->locked_until === null) {
            return;
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
    }
}
