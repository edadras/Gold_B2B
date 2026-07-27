<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\LedgerAdjustmentPoster;
use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\AdjustmentStatus;
use App\Modules\Admin\Domain\DualControlPolicy;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Admin\Infrastructure\Models\LedgerAdjustmentRequest;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The most dangerous operation in the platform, and the one with the most locks
 * on it (docs/08-frontend-web/01-web-panels.md §1.10).
 *
 * The screen creates a *request*. Nothing touches `ledger_entries` until a
 * second person with a different role approves it. Every refusal below is a
 * separate check with its own message, because "denied" without a reason is
 * what drives operators to work around a control:
 *
 *   1. the maker must hold SETTLEMENT_OFFICER;
 *   2. the reason must be at least 50 characters — the form says «اجباری و
 *      مفصل» and a 50-character floor is what makes that testable;
 *   3. a supporting document is mandatory;
 *   4. the checker must hold PLATFORM_ADMIN;
 *   5. maker and checker must be different people;
 *   6. the checker must bring a role the maker did not act under;
 *   7. the posting is a balanced pair in one transaction group, so conservation
 *      of mass survives the correction.
 *
 * Audit rows are written after the database transaction returns, never inside
 * it (AGENT_BRIEF rule 3).
 */
final class ManualAdjustmentService
{
    public function __construct(
        private readonly LedgerAdjustmentPoster $poster,
        private readonly IdentityDirectory $directory,
        private readonly DualControlPolicy $policy,
        private readonly AdminAuditor $auditor,
    ) {}

    /**
     * Step one: record the request. This never writes to the ledger.
     */
    public function request(
        int $makerUserId,
        int $organizationId,
        AdjustmentAsset $asset,
        int $amount,
        OffsetAccount $offset,
        string $reason,
        string $documentPath,
        ?string $documentName = null,
        ?string $documentHash = null,
    ): LedgerAdjustmentRequest {
        $maker = $this->directory->findUser($makerUserId);

        if ($maker === null || ! $this->policy->mayMake($maker)) {
            $this->auditor->denied(
                'admin.ledger_adjustment.request',
                'LedgerAdjustmentRequest',
                null,
                'maker does not hold a maker role',
                $makerUserId,
            );

            throw new OperationNotPermittedException(
                'تنها افسر تسویه می‌تواند درخواست اصلاح دستی دفتر ثبت کند.'
            );
        }

        $this->assertReasonIsAnExplanation($reason);

        if (trim($documentPath) === '') {
            throw new OperationNotPermittedException('ثبت درخواست بدون مستند پشتیبان مجاز نیست.');
        }

        if ($amount === 0) {
            throw new OperationNotPermittedException('مبلغ اصلاح نمی‌تواند صفر باشد.');
        }

        if (! $offset->supports($asset)) {
            throw new OperationNotPermittedException(sprintf(
                'حساب طرف مقابل «%s» برای دارایی %s تعریف نشده است.',
                $offset->label(),
                $asset->label(),
            ));
        }

        if ($this->directory->findOrganization($organizationId) === null) {
            throw new OperationNotPermittedException("سازمان {$organizationId} یافت نشد.");
        }

        $request = new LedgerAdjustmentRequest;
        $request->forceFill([
            'reference' => 'ADJ-'.strtoupper(Str::random(12)),
            'organization_id' => $organizationId,
            'asset_type' => $asset->value,
            'amount' => $amount,
            'offset_account' => $offset->value,
            'reason' => $reason,
            'supporting_document_path' => $documentPath,
            'supporting_document_name' => $documentName,
            'supporting_document_hash' => $documentHash,
            'status' => AdjustmentStatus::PENDING_APPROVAL->value,
            'requested_by_user_id' => $makerUserId,
            'requested_at' => now(),
        ]);
        $request->save();

        $this->auditor->action(
            action: 'admin.ledger_adjustment.requested',
            subjectType: 'LedgerAdjustmentRequest',
            subjectId: (int) $request->id,
            note: $reason,
            after: $this->snapshot($request),
            organizationId: $organizationId,
            actorId: $makerUserId,
            metadata: ['reference' => $request->reference],
        );

        return $request;
    }

    /**
     * Step two: a second person approves, and only then does the ledger move.
     */
    public function approveAndPost(
        int $requestId,
        int $checkerUserId,
        string $decisionNote,
    ): LedgerAdjustmentRequest {
        $request = LedgerAdjustmentRequest::query()->findOrFail($requestId);

        if ($request->status !== AdjustmentStatus::PENDING_APPROVAL) {
            throw new InvalidStateTransitionException(
                'LedgerAdjustmentRequest',
                $request->status->value,
                AdjustmentStatus::POSTED->value,
            );
        }

        if (trim($decisionNote) === '') {
            throw new OperationNotPermittedException('تأیید بدون یادداشت مجاز نیست.');
        }

        $this->assertCheckerMayApprove($request, $checkerUserId);

        $before = $this->snapshot($request);

        $posted = $this->poster->post(
            organizationId: $request->organization_id,
            asset: $request->asset_type,
            amount: $request->amount,
            offset: $request->offset_account,
            adjustmentRequestId: (int) $request->id,
            description: 'اصلاح دستی دفتر — '.$request->reference,
            postedByUserId: $checkerUserId,
        );

        DB::transaction(function () use ($request, $checkerUserId, $decisionNote, $posted): void {
            $request->forceFill([
                'status' => AdjustmentStatus::POSTED->value,
                'approved_by_user_id' => $checkerUserId,
                'approved_at' => now(),
                'decision_note' => $decisionNote,
                'posted_at' => now(),
                'transaction_group' => $posted->transactionGroup,
                'member_entry_id' => $posted->entryIds[0],
                'offset_entry_id' => $posted->entryIds[1],
            ])->save();
        });

        // Outside the transaction, as rule 3 requires.
        $this->auditor->action(
            action: 'admin.ledger_adjustment.posted',
            subjectType: 'LedgerAdjustmentRequest',
            subjectId: (int) $request->id,
            note: $decisionNote,
            before: $before,
            after: $this->snapshot($request->refresh()),
            organizationId: $request->organization_id,
            actorId: $checkerUserId,
            metadata: [
                'transaction_group' => $posted->transactionGroup,
                'entry_ids' => $posted->entryIds,
                'maker_user_id' => $request->requested_by_user_id,
            ],
        );

        return $request;
    }

    public function reject(int $requestId, int $checkerUserId, string $decisionNote): LedgerAdjustmentRequest
    {
        $request = LedgerAdjustmentRequest::query()->findOrFail($requestId);

        if ($request->status !== AdjustmentStatus::PENDING_APPROVAL) {
            throw new InvalidStateTransitionException(
                'LedgerAdjustmentRequest',
                $request->status->value,
                AdjustmentStatus::REJECTED->value,
            );
        }

        if (trim($decisionNote) === '') {
            throw new OperationNotPermittedException('رد درخواست بدون یادداشت مجاز نیست.');
        }

        $this->assertCheckerMayApprove($request, $checkerUserId);

        $before = $this->snapshot($request);

        $request->forceFill([
            'status' => AdjustmentStatus::REJECTED->value,
            'approved_by_user_id' => $checkerUserId,
            'approved_at' => now(),
            'decision_note' => $decisionNote,
        ])->save();

        $this->auditor->action(
            action: 'admin.ledger_adjustment.rejected',
            subjectType: 'LedgerAdjustmentRequest',
            subjectId: (int) $request->id,
            note: $decisionNote,
            before: $before,
            after: $this->snapshot($request),
            organizationId: $request->organization_id,
            actorId: $checkerUserId,
        );

        return $request;
    }

    /**
     * The three dual-control refusals, in the order a reader would check them.
     */
    private function assertCheckerMayApprove(LedgerAdjustmentRequest $request, int $checkerUserId): void
    {
        $checker = $this->directory->findUser($checkerUserId);

        if ($checker === null || ! $this->policy->mayCheck($checker)) {
            $this->deny($request, $checkerUserId, 'checker does not hold PLATFORM_ADMIN');

            throw new OperationNotPermittedException(
                'تأیید اصلاح دستی دفتر تنها از عهده مدیر پلتفرم برمی‌آید.'
            );
        }

        if (! $this->policy->areDistinctPeople($request->requested_by_user_id, $checkerUserId)) {
            $this->deny($request, $checkerUserId, 'maker and checker are the same user');

            throw new OperationNotPermittedException(
                'ثبت‌کننده و تأییدکننده نمی‌توانند یک نفر باشند.'
            );
        }

        $maker = $this->directory->findUser($request->requested_by_user_id);

        if ($maker === null) {
            $this->deny($request, $checkerUserId, 'maker no longer exists');

            throw new OperationNotPermittedException('کاربر ثبت‌کننده درخواست دیگر وجود ندارد.');
        }

        if (! $this->policy->bringsADifferentRole($maker, $checker)) {
            $this->deny($request, $checkerUserId, 'checker holds no role the maker lacks');

            throw new OperationNotPermittedException(
                'تأییدکننده باید نقشی متفاوت از ثبت‌کننده داشته باشد.'
            );
        }
    }

    private function assertReasonIsAnExplanation(string $reason): void
    {
        if (mb_strlen(trim($reason)) < LedgerAdjustmentRequest::MIN_REASON_LENGTH) {
            throw new OperationNotPermittedException(sprintf(
                'دلیل اصلاح باید دست‌کم %d نویسه باشد.',
                LedgerAdjustmentRequest::MIN_REASON_LENGTH,
            ));
        }
    }

    private function deny(LedgerAdjustmentRequest $request, int $checkerUserId, string $reason): void
    {
        $this->auditor->denied(
            'admin.ledger_adjustment.approve',
            'LedgerAdjustmentRequest',
            (int) $request->id,
            $reason,
            $checkerUserId,
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(LedgerAdjustmentRequest $request): array
    {
        return [
            'reference' => $request->reference,
            'organization_id' => $request->organization_id,
            'asset_type' => $request->asset_type->value,
            'amount' => $request->amount,
            'offset_account' => $request->offset_account->value,
            'status' => $request->status->value,
            'requested_by_user_id' => $request->requested_by_user_id,
            'approved_by_user_id' => $request->approved_by_user_id,
            'transaction_group' => $request->transaction_group,
        ];
    }
}
