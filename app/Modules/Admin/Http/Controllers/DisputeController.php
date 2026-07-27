<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\DisputeCaseService;
use App\Modules\Admin\Http\Requests\AssignMediatorRequest;
use App\Modules\Admin\Infrastructure\Tables\TableDisputeAdminAdapter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DisputeController extends AdminController
{
    public function __construct(private readonly DisputeCaseService $disputes) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        return view('admin::pages.disputes.index', [
            'disputes' => $this->disputes->queue(
                $status === '' ? TableDisputeAdminAdapter::OPEN_STATUSES : [$status],
            ),
            'activeStatus' => $status,
            'statuses' => TableDisputeAdminAdapter::OPEN_STATUSES,
        ]);
    }

    public function show(int $dispute): View
    {
        return view('admin::pages.disputes.show', [
            'context' => $this->disputes->openCase($dispute, $this->actorId()),
        ]);
    }

    public function assignMediator(AssignMediatorRequest $request, int $dispute): RedirectResponse
    {
        $this->disputes->assignMediator(
            disputeId: $dispute,
            mediatorUserId: (int) $request->validated('mediator_user_id'),
            actorUserId: $this->actorId(),
            note: (string) $request->validated('note'),
        );

        return redirect()->route('admin.disputes.show', $dispute)->with('status', 'میانجی تعیین شد.');
    }
}
