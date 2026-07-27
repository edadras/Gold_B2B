<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\AdminAuditor;
use App\Modules\Admin\Http\Requests\LoginRequest;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\Totp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session login for the panel.
 *
 * Two things are deliberate:
 *
 *   · a non-staff account is refused at the login step, not merely at the first
 *     page — a member who can create an admin session, however briefly, is a
 *     member who has an admin session;
 *   · when the account has TOTP confirmed, the code is required. §1.11 asks for
 *     mandatory 2FA for all staff; enforcing it for accounts that have it set
 *     up is what can be done without locking out an operator who has not
 *     enrolled yet. Making enrolment itself compulsory is Identity's call and
 *     is noted as unfinished.
 *
 * The user model is never imported: the guard's Authenticatable is enough, and
 * the two attributes needed are read dynamically. Admin may depend on Identity,
 * but only on its published surface.
 */
final class AuthController extends AdminController
{
    public function __construct(
        private readonly IdentityDirectory $directory,
        private readonly AdminAuditor $auditor,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin::pages.auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = [
            'mobile' => (string) $request->validated('mobile'),
            'password' => (string) $request->validated('password'),
        ];

        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'mobile' => 'نام کاربری یا گذرواژه نادرست است.',
            ]);
        }

        $candidate = Auth::getProvider()->retrieveByCredentials($credentials);

        if ($candidate === null) {
            throw ValidationException::withMessages([
                'mobile' => 'نام کاربری یا گذرواژه نادرست است.',
            ]);
        }

        $userId = (int) $candidate->getAuthIdentifier();
        $snapshot = $this->directory->findUser($userId);

        if ($snapshot === null || ! $this->isStaff($snapshot->roles)) {
            $this->auditor->denied(
                action: 'admin.login',
                subjectType: 'User',
                subjectId: $userId,
                reason: 'non-staff account attempted to sign in to the admin panel',
                actorId: $userId,
            );

            throw ValidationException::withMessages([
                'mobile' => 'این حساب اجازه ورود به پنل ادمین را ندارد.',
            ]);
        }

        $this->assertSecondFactor($candidate, (string) ($request->validated('code') ?? ''));

        Auth::login($candidate);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('admin.login');
    }

    /** @param list<string> $roles */
    private function isStaff(array $roles): bool
    {
        foreach ($roles as $name) {
            $role = Role::tryFrom($name);

            if ($role !== null && $role->isPlatformRole()) {
                return true;
            }
        }

        return false;
    }

    private function assertSecondFactor(mixed $candidate, string $code): void
    {
        $confirmedAt = $candidate->getAttribute('two_factor_confirmed_at');
        $secret = $candidate->getAttribute('two_factor_secret_enc');

        if ($confirmedAt === null || ! is_string($secret) || $secret === '') {
            return;
        }

        if ($code === '' || ! Totp::verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => 'کد تأیید دومرحله‌ای نادرست است.',
            ]);
        }
    }
}
