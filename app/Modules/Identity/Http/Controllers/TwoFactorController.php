<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Http\Requests\TwoFactorCodeRequest;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/auth/2fa/*` — TOTP enrolment. */
final class TwoFactorController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly AuthService $auth,
    ) {
        parent::__construct($authorization);
    }

    /**
     * Hands back the shared secret and an otpauth:// URI for the QR code.
     *
     * The secret is only usable once confirm() has proved the user can produce
     * a code from it, so an abandoned enrolment cannot lock anybody out.
     */
    public function enable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $enrolment = $this->auth->beginTwoFactorEnrolment($user);

        return ApiResponse::item([
            'secret' => $enrolment['secret'],
            'otpauth_uri' => $enrolment['uri'],
            'digits' => 6,
            'period_seconds' => 30,
            'confirmed' => false,
        ]);
    }

    public function confirm(TwoFactorCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->auth->confirmTwoFactorEnrolment($user, (string) $request->validated('code'))) {
            return ApiResponse::error('AUTH_2FA_INVALID', 'کد تأیید نادرست است.', 422);
        }

        return ApiResponse::item(['confirmed' => true]);
    }

    /**
     * Disabling is itself a signed action (✍️ in the catalogue): the
     * `transaction.sign` middleware on the route has already proved the caller
     * holds the authenticator before we reach this method.
     */
    public function disable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret_enc' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_counter' => null,
        ])->save();

        return ApiResponse::noContent();
    }
}
