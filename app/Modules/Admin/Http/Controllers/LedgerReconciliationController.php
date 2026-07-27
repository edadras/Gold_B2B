<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\LedgerReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LedgerReconciliationController extends AdminController
{
    public function __construct(private readonly LedgerReconciliationService $reconciliation) {}

    public function index(): View
    {
        return view('admin::pages.ledger.reconciliation', [
            'report' => $this->reconciliation->report(),
            'lastRunAt' => $this->reconciliation->lastRunAt(),
        ]);
    }

    /** Per-account rebuild: stored balance beside one recomputed from entries. */
    public function account(int $account): View
    {
        $rebuild = $this->reconciliation->rebuildAccount($account);

        if ($rebuild === null) {
            throw new NotFoundHttpException('حساب دفتر یافت نشد.');
        }

        return view('admin::pages.ledger.account', ['rebuild' => $rebuild]);
    }

    public function organization(Request $request): View
    {
        $organizationId = (int) $request->query('organization_id', '0');

        return view('admin::pages.ledger.organization', [
            'organizationId' => $organizationId,
            'rebuilds' => $organizationId > 0
                ? $this->reconciliation->rebuildForOrganization($organizationId)
                : [],
        ]);
    }
}
