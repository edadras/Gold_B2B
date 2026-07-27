<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\SettlementMonitorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SettlementController extends AdminController
{
    public function __construct(private readonly SettlementMonitorService $monitor) {}

    public function index(Request $request): View
    {
        $tab = (string) $request->query('tab', 'open');

        if (! array_key_exists($tab, SettlementMonitorService::TABS)) {
            $tab = 'open';
        }

        return view('admin::pages.settlements.index', [
            'tab' => $tab,
            'tabs' => array_keys(SettlementMonitorService::TABS),
            'counts' => $this->monitor->tabCounts(),
            'rows' => $this->monitor->tab($tab),
            'health' => $this->monitor->health(),
        ]);
    }

    public function show(int $settlement): View
    {
        $detail = $this->monitor->detail($settlement);

        if ($detail === null) {
            throw new NotFoundHttpException('تسویه یافت نشد.');
        }

        return view('admin::pages.settlements.show', $detail);
    }
}
