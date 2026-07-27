<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\VaultInventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class VaultController extends AdminController
{
    public function __construct(private readonly VaultInventoryService $inventory) {}

    public function index(): View
    {
        return view('admin::pages.vaults.index', ['vaults' => $this->inventory->vaults()]);
    }

    public function show(Request $request, int $vault): View
    {
        $status = (string) $request->query('status', '');

        return view('admin::pages.vaults.show', [
            'vaultId' => $vault,
            'lots' => $this->inventory->lots($vault, $status === '' ? [] : [$status]),
            'reconciliation' => $this->inventory->reconcile($vault),
            'activeStatus' => $status,
        ]);
    }
}
