<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\AmlCaseService;
use App\Modules\Admin\Http\Requests\AmlDecisionRequest;
use App\Modules\Admin\Infrastructure\Tables\TableAmlAdminAdapter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AmlController extends AdminController
{
    public function __construct(private readonly AmlCaseService $aml) {}

    public function index(Request $request): View
    {
        $severity = (string) $request->query('severity', '');
        $status = (string) $request->query('status', '');

        return view('admin::pages.aml.index', [
            'flags' => $this->aml->queue(
                statuses: $status === '' ? TableAmlAdminAdapter::OPEN_STATUSES : [$status],
                severities: $severity === '' ? [] : [$severity],
            ),
            'activeSeverity' => $severity,
            'activeStatus' => $status,
            'statuses' => TableAmlAdminAdapter::OPEN_STATUSES,
            'decisionStatuses' => TableAmlAdminAdapter::DECISION_STATUSES,
        ]);
    }

    public function show(int $flag): View
    {
        return view('admin::pages.aml.show', [
            'context' => $this->aml->openCase($flag, $this->actorId()),
            'decisionStatuses' => TableAmlAdminAdapter::DECISION_STATUSES,
        ]);
    }

    public function decide(AmlDecisionRequest $request, int $flag): RedirectResponse
    {
        $this->aml->decide(
            flagId: $flag,
            analystUserId: $this->actorId(),
            status: (string) $request->validated('status'),
            notes: (string) $request->validated('notes'),
            actionTaken: $request->validated('action_taken'),
        );

        return redirect()->route('admin.aml.index')->with('status', 'وضعیت پرچم به‌روزرسانی شد.');
    }
}
