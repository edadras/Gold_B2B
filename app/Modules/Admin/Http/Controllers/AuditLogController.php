<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AuditLogController extends AdminController
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request): View
    {
        $filters = [
            'actor_id' => $request->query('actor_id'),
            'subject_type' => $request->query('subject_type'),
            'subject_id' => $request->query('subject_id'),
            'action' => $request->query('action'),
            'organization_id' => $request->query('organization_id'),
            'result' => $request->query('result'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        return view('admin::pages.audit.index', $this->auditLog->search(
            $filters,
            (int) $request->query('page', '1'),
        ) + [
            'filters' => $filters,
            'actions' => $this->auditLog->actions(),
        ]);
    }
}
