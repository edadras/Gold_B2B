<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Application\AdminAuditor;
use App\Modules\Identity\Contracts\IdentityDirectory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-screen permission, on top of "is staff at all".
 *
 * This is what keeps AML behind its own key (§1.11: «دسترسی AML جدا از دسترسی
 * عمومی ادمین»): a settlement officer is platform staff and still gets 403 on
 * `/admin/aml`, because they do not hold `platform.aml.manage`.
 *
 * Usage: `->middleware('admin.can:platform.aml.manage')`. Several permissions
 * may be listed and any one of them suffices.
 */
final class RequirePlatformPermission
{
    public function __construct(
        private readonly IdentityDirectory $directory,
        private readonly AdminAuditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $userId = Auth::id();
        $user = $userId === null ? null : $this->directory->findUser((int) $userId);

        if ($user === null) {
            abort(403);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        $this->auditor->denied(
            action: 'admin.permission_denied',
            subjectType: 'AdminRoute',
            subjectId: null,
            reason: implode(',', $permissions).' required for '.$request->path(),
            actorId: $user->id,
        );

        abort(403, 'برای این بخش مجوز لازم را ندارید.');
    }
}
