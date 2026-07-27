<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\SettingsAdminService;
use App\Modules\Admin\Http\Requests\UpdateSettingRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class SettingsController extends AdminController
{
    public function __construct(private readonly SettingsAdminService $settings) {}

    public function index(): View
    {
        return view('admin::pages.settings.index', ['groups' => $this->settings->grouped()]);
    }

    public function update(UpdateSettingRequest $request): RedirectResponse
    {
        $this->settings->update(
            key: (string) $request->validated('key'),
            rawValue: (string) $request->validated('value'),
            actorUserId: $this->actorId(),
            note: (string) $request->validated('note'),
        );

        return redirect()->route('admin.settings.index')->with('status', 'تنظیم به‌روزرسانی شد.');
    }
}
