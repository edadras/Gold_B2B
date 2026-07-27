<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Application\AdminAuditor;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Domain\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/08-frontend-web/01-web-panels.md §1.11 — the panel is for the operator's
 * own staff and no one else.
 *
 * Membership is decided from the user's *roles*, read through Identity's
 * published directory, not from a flag on the user row: a role grant is the
 * thing that gets audited and revoked, so it is the thing worth trusting.
 *
 * A member user gets 403 on every admin route, and the refusal is audited —
 * a member reaching an admin URL is either a bug or an attack and both deserve
 * a record.
 */
final class PlatformStaffOnly
{
    public function __construct(
        private readonly IdentityDirectory $directory,
        private readonly AdminAuditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Auth::id();

        if ($userId === null) {
            abort(403, 'دسترسی به پنل ادمین تنها برای کارکنان پلتفرم است.');
        }

        $user = $this->directory->findUser((int) $userId);

        if ($user === null || ! $this->isPlatformStaff($user->roles)) {
            $this->auditor->denied(
                action: 'admin.access',
                subjectType: 'AdminRoute',
                subjectId: null,
                reason: 'non-staff user requested '.$request->path(),
                actorId: (int) $userId,
            );

            abort(403, 'دسترسی به پنل ادمین تنها برای کارکنان پلتفرم است.');
        }

        return $next($request);
    }

    /** @param list<string> $roles */
    private function isPlatformStaff(array $roles): bool
    {
        foreach ($roles as $name) {
            $role = Role::tryFrom($name);

            if ($role !== null && $role->isPlatformRole()) {
                return true;
            }
        }

        return false;
    }
}
