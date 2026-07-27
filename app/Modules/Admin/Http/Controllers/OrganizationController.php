<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\OrganizationAdminService;
use App\Modules\Admin\Http\Requests\OrganizationStatusRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrganizationController extends AdminController
{
    public function __construct(private readonly OrganizationAdminService $organizations) {}

    public function index(Request $request): View
    {
        $search = $request->query('q');
        $status = (string) $request->query('status', '');

        return view('admin::pages.organizations.index', [
            'organizations' => $this->organizations->list(
                is_string($search) ? $search : null,
                $status === '' ? [] : [$status],
            ),
            'search' => is_string($search) ? $search : '',
            'activeStatus' => $status,
        ]);
    }

    public function show(int $organization): View
    {
        $detail = $this->organizations->detail($organization);

        if ($detail === null) {
            throw new NotFoundHttpException('سازمان یافت نشد.');
        }

        return view('admin::pages.organizations.show', $detail + [
            'transitions' => OrganizationAdminService::ALLOWED_TRANSITIONS,
        ]);
    }

    public function updateStatus(OrganizationStatusRequest $request, int $organization): RedirectResponse
    {
        $this->organizations->changeStatus(
            organizationId: $organization,
            target: (string) $request->validated('status'),
            actorUserId: $this->actorId(),
            reason: (string) $request->validated('reason'),
        );

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('status', 'وضعیت عضو تغییر کرد.');
    }
}
