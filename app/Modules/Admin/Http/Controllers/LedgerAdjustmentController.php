<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\ManualAdjustmentService;
use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\AdjustmentStatus;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Admin\Http\Requests\AdjustmentDecisionRequest;
use App\Modules\Admin\Http\Requests\StoreAdjustmentRequest;
use App\Modules\Admin\Infrastructure\Models\LedgerAdjustmentRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The manual ledger adjustment screen (§1.10).
 *
 * The controller does four things: validate the shape of the input, store the
 * supporting document, call the service, redirect. Every rule that matters —
 * who may raise, who may approve, how long the reason must be, what gets
 * posted — is in ManualAdjustmentService, where it is reachable from a test and
 * from any future entry point.
 *
 * There is no destroy() and there never will be: §1.11 forbids deleting a
 * financial record, and a request that has posted entries is one.
 */
final class LedgerAdjustmentController extends AdminController
{
    /** Where supporting documents land. Private disk, never public. */
    private const DOCUMENT_DIRECTORY = 'admin/ledger-adjustments';

    public function __construct(private readonly ManualAdjustmentService $adjustments) {}

    public function index(): View
    {
        return view('admin::pages.ledger.adjustments', [
            'pending' => LedgerAdjustmentRequest::query()
                ->where('status', AdjustmentStatus::PENDING_APPROVAL->value)
                ->orderBy('id')
                ->get(),
            'recent' => LedgerAdjustmentRequest::query()
                ->whereIn('status', [
                    AdjustmentStatus::POSTED->value,
                    AdjustmentStatus::REJECTED->value,
                    AdjustmentStatus::CANCELLED->value,
                ])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin::pages.ledger.adjustment-create', [
            'assets' => AdjustmentAsset::cases(),
            'offsets' => OffsetAccount::cases(),
        ]);
    }

    public function store(StoreAdjustmentRequest $request): RedirectResponse
    {
        $file = $request->file('supporting_document');
        $path = $file->store(self::DOCUMENT_DIRECTORY, 'local');

        $adjustment = $this->adjustments->request(
            makerUserId: $this->actorId(),
            organizationId: (int) $request->validated('organization_id'),
            asset: AdjustmentAsset::from((string) $request->validated('asset_type')),
            amount: (int) $request->validated('amount'),
            offset: OffsetAccount::from((string) $request->validated('offset_account')),
            reason: (string) $request->validated('reason'),
            documentPath: (string) $path,
            documentName: $file->getClientOriginalName(),
            documentHash: hash_file('sha256', $file->getRealPath()) ?: null,
        );

        return redirect()
            ->route('admin.ledger.adjustments.show', $adjustment->id)
            ->with('status', 'درخواست ثبت شد و منتظر تأیید مدیر پلتفرم است. بدون تأیید اجرا نمی‌شود.');
    }

    public function show(int $adjustment): View
    {
        $model = LedgerAdjustmentRequest::query()->find($adjustment);

        if ($model === null) {
            throw new NotFoundHttpException('درخواست اصلاح یافت نشد.');
        }

        return view('admin::pages.ledger.adjustment-show', ['adjustment' => $model]);
    }

    public function approve(AdjustmentDecisionRequest $request, int $adjustment): RedirectResponse
    {
        $this->adjustments->approveAndPost(
            requestId: $adjustment,
            checkerUserId: $this->actorId(),
            decisionNote: (string) $request->validated('decision_note'),
        );

        return redirect()
            ->route('admin.ledger.adjustments.show', $adjustment)
            ->with('status', 'اصلاح تأیید و در دفتر ثبت شد.');
    }

    public function reject(AdjustmentDecisionRequest $request, int $adjustment): RedirectResponse
    {
        $this->adjustments->reject(
            requestId: $adjustment,
            checkerUserId: $this->actorId(),
            decisionNote: (string) $request->validated('decision_note'),
        );

        return redirect()
            ->route('admin.ledger.adjustments.show', $adjustment)
            ->with('status', 'درخواست رد شد.');
    }
}
