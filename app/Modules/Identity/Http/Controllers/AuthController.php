<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\ApiTokenService;
use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Application\RegisterOrganizationService;
use App\Modules\Identity\Domain\Exceptions\TwoFactorRequiredException;
use App\Modules\Identity\Domain\Validators\MobileNormalizer;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RefreshTokenRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Requests\SendOtpRequest;
use App\Modules\Identity\Http\Requests\TwoFactorLoginRequest;
use App\Modules\Identity\Http\Requests\VerifyOtpRequest;
use App\Modules\Identity\Http\Resources\OrganizationResource;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Contracts\VerificationTierDirectory;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * `/auth/*` — docs/05-api/02-endpoints.md §2.1.
 *
 * Everything here is thin: AuthService owns lockout, TOTP and session
 * recording, ApiTokenService owns the refresh pair, OtpService owns one-time
 * codes. This class only shapes the envelope.
 */
final class AuthController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly AuthService $auth,
        private readonly ApiTokenService $tokens,
        private readonly OtpService $otp,
        private readonly RegisterOrganizationService $registrations,
        private readonly VerificationTierDirectory $tiers,
    ) {
        parent::__construct($authorization);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->registrations->register(
            $request->organizationAttributes(),
            $request->ownerAttributes(),
        );

        return ApiResponse::item([
            'organization' => OrganizationResource::summary($result['organization']),
            'owner' => new UserResource($result['owner']),
            // The organisation is PENDING until KYC clears; say so explicitly
            // so the client routes straight to the KYC wizard.
            'next_step' => 'KYC_SUBMISSION',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->auth->login(
                (string) $request->validated('mobile'),
                (string) $request->validated('password'),
                $this->context($request),
            );
        } catch (TwoFactorRequiredException $e) {
            // Not an error from the client's point of view: it is step one of
            // two completing successfully. The documented shape in §2.1 is a
            // 200 with requires_2fa, so the exception is translated here rather
            // than left to the generic DomainException renderer.
            return ApiResponse::item([
                'requires_2fa' => true,
                'challenge_token' => $e->challengeId,
                'methods' => [$e->method],
            ]);
        }

        return ApiResponse::item($this->tokenPayload($result));
    }

    public function loginTwoFactor(TwoFactorLoginRequest $request): JsonResponse
    {
        $result = $this->auth->completeTwoFactorChallenge(
            $request->challenge(),
            (string) $request->validated('code'),
        );

        return ApiResponse::item($this->tokenPayload($result));
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $result = $this->tokens->refresh((string) $request->validated('refresh_token'));

        return ApiResponse::item($this->tokenPayload($result, $result['refresh_token']));
    }

    /**
     * CONTRACT GAP RESOLVED (#3). There was no /auth/me, so a cold start had to
     * infer identity from GET /organization and assume no permissions — which
     * meant the app either hid features the user had or showed features it
     * would then be refused.
     *
     * This returns everything the client needs to render its navigation without
     * a second call: the user, their roles, their resolved permission set, and
     * the organisation summary including the flags that gate trading.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('roles');

        /** @var Organization|null $organization */
        $organization = Organization::query()->find($user->organization_id);

        return ApiResponse::item([
            'user' => new UserResource($user),
            'organization' => $organization === null
                ? null
                : OrganizationResource::summary($organization, $this->tiers->tierFor((int) $organization->id)),
            'permissions' => $user->permissionNames(),
            'session' => [
                'id' => $this->currentSession($request)?->id,
                'expires_at' => $this->currentSession($request)?->expires_at?->toIso8601String(),
                'two_factor_satisfied' => (bool) ($this->currentSession($request)?->two_factor_satisfied ?? false),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);

        if ($session !== null) {
            $this->auth->logout($session);
        }

        // The bearer token itself must die with the session, or the "logged
        // out" device keeps working until the 15 minutes are up.
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::noContent();
    }

    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $this->auth->revokeAllSessions($user, 'user_logout_all');

        return ApiResponse::item(['revoked_sessions' => $revoked]);
    }

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $result = $this->otp->send(
            (string) $request->validated('mobile'),
            $request->purpose(),
        );

        return ApiResponse::item(array_filter([
            'mobile' => $result['mobile'],
            'expires_in' => $result['expires_in'],
            'debug_code' => $result['debug_code'],
        ], static fn ($v) => $v !== null));
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $ticket = $this->otp->verify(
            (string) $request->validated('mobile'),
            $request->purpose(),
            (string) $request->validated('code'),
        );

        if ($ticket === null) {
            return ApiResponse::error('AUTH_2FA_INVALID', 'کد وارد شده نادرست یا منقضی است.', 401);
        }

        return ApiResponse::item([
            'verified' => true,
            'ticket' => $ticket,
            'expires_in' => OtpService::TICKET_TTL_SECONDS,
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $mobile = (string) $request->validated('mobile');

        // Always answers the same way. Telling the caller whether the number is
        // registered turns this endpoint into a membership oracle.
        $normalized = MobileNormalizer::normalize($mobile);

        if ($normalized !== null && User::query()->where('mobile', $normalized)->exists()) {
            $this->otp->send($mobile, 'PASSWORD_RESET');
        }

        return ApiResponse::item([
            'sent' => true,
            'expires_in' => OtpService::CODE_TTL_SECONDS,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $mobile = (string) $request->validated('mobile');

        if (! $this->otp->consumeTicket((string) $request->validated('ticket'), 'PASSWORD_RESET', $mobile)) {
            return ApiResponse::error('AUTH_2FA_INVALID', 'اعتبار این درخواست منقضی شده است.', 401);
        }

        $normalized = MobileNormalizer::normalize($mobile);

        /** @var User|null $user */
        $user = $normalized === null ? null : User::query()->where('mobile', $normalized)->first();

        if ($user === null) {
            return ApiResponse::error('AUTH_INVALID_CREDENTIALS', 'کاربری با این شماره یافت نشد.', 401);
        }

        $user->forceFill([
            'password_hash' => Hash::make((string) $request->validated('password')),
            'password_changed_at' => now(),
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        // A password change invalidates every live session: if the reset was
        // triggered by a compromise, the attacker's token must not survive it.
        $this->auth->revokeAllSessions($user, 'password_reset');

        return ApiResponse::item(['reset' => true]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->validated('current_password'), $user->getAuthPassword())) {
            return ApiResponse::error('AUTH_INVALID_CREDENTIALS', 'رمز عبور فعلی نادرست است.', 401);
        }

        $user->forceFill([
            'password_hash' => Hash::make((string) $request->validated('password')),
            'password_changed_at' => now(),
        ])->save();

        $this->auth->revokeAllSessions($user, 'password_changed');

        return ApiResponse::item(['changed' => true, 'sessions_revoked' => true]);
    }

    /**
     * @param  array{token: string, user: User, session: UserSession, expires_at: string}  $result
     * @return array<string, mixed>
     */
    private function tokenPayload(array $result, ?string $refreshToken = null): array
    {
        $user = $result['user'];
        $user->loadMissing('roles');

        /** @var Organization|null $organization */
        $organization = Organization::query()->find($user->organization_id);

        return [
            'access_token' => $result['token'],
            'refresh_token' => $refreshToken ?? $this->tokens->issueRefreshToken($user, $result['session']),
            'token_type' => 'Bearer',
            'expires_in' => AuthService::ACCESS_TOKEN_MINUTES * 60,
            'expires_at' => $result['expires_at'],
            'user' => new UserResource($user),
            'organization' => $organization === null
                ? null
                : OrganizationResource::summary($organization, $this->tiers->tierFor((int) $organization->id)),
        ];
    }

    private function currentSession(Request $request): ?UserSession
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        /** @var UserSession|null */
        return UserSession::query()
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /** @return array<string, mixed> */
    private function context(Request $request): array
    {
        return array_filter([
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
            'device_name' => $request->input('device_name'),
            'platform' => $request->input('platform'),
            'app_version' => $request->input('app_version'),
        ], static fn ($v) => $v !== null);
    }
}
