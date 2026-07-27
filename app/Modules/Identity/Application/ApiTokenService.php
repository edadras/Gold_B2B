<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The refresh half of the token pair documented in §2.1's login response.
 *
 * AuthService issues the 15-minute access token. It does not issue a refresh
 * token, because a refresh token is an HTTP-transport concern rather than an
 * authentication one — so it is minted here, next to the endpoint that spends
 * it.
 *
 * Implementation: a second Sanctum token with the single ability `auth:refresh`
 * and a 30-day expiry, named after the session it belongs to. That means no new
 * table, and revoking a session's tokens (logout, password change) revokes the
 * refresh token with it.
 *
 * Refresh tokens ROTATE. Spending one deletes it and issues a new pair, so a
 * stolen refresh token is usable at most once and the theft shows up as the
 * legitimate client being logged out.
 */
final class ApiTokenService
{
    public const REFRESH_ABILITY = 'auth:refresh';

    public const REFRESH_TTL_DAYS = 30;

    public function issueRefreshToken(User $user, UserSession $session): string
    {
        return $user->createToken(
            name: 'refresh:'.$session->id,
            abilities: [self::REFRESH_ABILITY],
            expiresAt: now()->addDays(self::REFRESH_TTL_DAYS),
        )->plainTextToken;
    }

    /**
     * Spend a refresh token for a fresh access/refresh pair.
     *
     * @return array{token: string, refresh_token: string, user: User, session: UserSession, expires_at: string}
     *
     * @throws InvalidCredentialsException
     */
    public function refresh(string $refreshToken): array
    {
        $token = PersonalAccessToken::findToken($refreshToken);

        if ($token === null
            || ! $token->can(self::REFRESH_ABILITY)
            || ($token->expires_at !== null && $token->expires_at->isPast())) {
            throw new InvalidCredentialsException;
        }

        /** @var User|null $user */
        $user = $token->tokenable;

        if (! $user instanceof User || $user->status !== UserStatus::ACTIVE) {
            throw new InvalidCredentialsException;
        }

        $sessionId = (int) str_replace('refresh:', '', (string) $token->name);

        /** @var UserSession|null $session */
        $session = UserSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $user->id)
            ->first();

        if ($session === null || ! $session->isActive()) {
            throw new InvalidCredentialsException;
        }

        // Rotation: the spent token dies before the replacement is minted.
        $token->delete();

        $expiresAt = now()->addMinutes(AuthService::ACCESS_TOKEN_MINUTES);

        $access = $user->createToken(
            name: (string) ($session->device_id ?? 'web'),
            abilities: $user->permissionNames() === [] ? ['*'] : $user->permissionNames(),
            expiresAt: $expiresAt,
        );

        $session->forceFill([
            'token_hash' => hash('sha256', $access->plainTextToken),
            'last_seen_at' => now(),
            'expires_at' => $expiresAt,
        ])->save();

        return [
            'token' => $access->plainTextToken,
            'refresh_token' => $this->issueRefreshToken($user, $session),
            'user' => $user,
            'session' => $session,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
