<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\KycQueueService;
use App\Modules\Admin\Http\Requests\KycDecisionRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class KycController extends AdminController
{
    public function __construct(private readonly KycQueueService $kyc) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        $statuses = $status === ''
            ? KycQueueService::REVIEWABLE_STATUSES
            : [$status];

        return view('admin::pages.kyc.index', [
            'items' => $this->kyc->queue($statuses),
            'activeStatus' => $status,
            'statuses' => KycQueueService::REVIEWABLE_STATUSES,
        ]);
    }

    public function show(int $organization): View
    {
        return view('admin::pages.kyc.show', [
            'dossier' => $this->kyc->openForReview($organization, $this->actorId()),
        ]);
    }

    /** Reveals one document's metadata — a separate, separately audited act. */
    public function document(int $organization, int $document): View
    {
        return view('admin::pages.kyc.document', [
            'organizationId' => $organization,
            'document' => $this->kyc->viewDocument($document, $this->actorId()),
        ]);
    }

    public function decide(KycDecisionRequest $request, int $organization): RedirectResponse
    {
        $this->kyc->decide(
            organizationId: $organization,
            officerUserId: $this->actorId(),
            decision: (string) $request->validated('decision'),
            notes: (string) $request->validated('notes'),
        );

        return redirect()
            ->route('admin.kyc.index')
            ->with('status', 'تصمیم ثبت شد.');
    }
}
