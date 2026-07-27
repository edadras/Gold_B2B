<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\DashboardService;
use Illuminate\Contracts\View\View;

final class DashboardController extends AdminController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(): View
    {
        return view('admin::pages.dashboard', $this->dashboard->build());
    }
}
