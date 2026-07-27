<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\KycAdminPort;
use App\Modules\Admin\Contracts\KycDossier;
use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;

/**
 * The compliance officer's queue and review workflow.
 *
 * Two rules from §1.11 live here rather than in the controller, because a
 * second entry point (a console command, a future API) must not be able to
 * skip them:
 *
 *   · «هیچ کارمندی نتواند سازمان خودش را بررسی کند» — assertMayReview() refuses
 *     before the dossier is even loaded, so an officer cannot so much as read
 *     their own organisation's file from the review screen;
 *   · «هر اقدام نیازمند یادداشت» — every decision carries a written note, and
 *     an empty one is refused.
 *
 * The organisation's own status transition goes through Identity's
 * `OrganizationLifecycle`, so the state machine, its status-event row and its
 * domain events all still happen.
 */
final class KycQueueService
{
    public const REVIEWABLE_STATUSES = ['SUBMITTED', 'IN_REVIEW', 'INFO_REQUIRED'];

    public function __construct(
        private readonly KycAdminPort $kyc,
        private readonly IdentityDirectory $directory,
        private readonly OrganizationLifecycle $organizations,
        private readonly AdminAuditor $auditor,
    ) {}

    /**
     * @param  list<string>  $statuses
     * @return list<\App\Modules\Admin\Contracts\KycQueueItem>
     */
    public function queue(array $statuses = self::REVIEWABLE_STATUSES, int $limit = 100): array
    {
        return $this->kyc->queue($statuses, $limit);
    }

    /**
     * Open a dossier for review.
     *
     * Loading a dossier reveals identity verdicts and document metadata, which
     * is sensitive data — so the read is audited, exactly as §1.11 requires.
     */
    public function openForReview(int $organizationId, int $officerUserId): KycDossier
    {
        $this->assertMayReview($organizationId, $officerUserId);

        $dossier = $this->kyc->dossier($organizationId);

        if ($dossier === null) {
            throw new OperationNotPermittedException('پرونده KYC برای این سازمان وجود ندارد.');
        }

        $this->auditor->viewSensitive(
            subjectType: 'KycProfile',
            subjectId: $dossier->profileId,
            fields: ['identity_checks', 'documents', 'bank_accounts'],
            organizationId: $organizationId,
            actorId: $officerUserId,
        );

        return $dossier;
    }

    /**
     * Reveal one document's metadata, e.g. before streaming the file.
     *
     * A separate audit row from the dossier read: "opened the file" and
     * "skimmed the list" are different acts and a regulator will ask which one
     * happened.
     *
     * @return array<string, mixed>
     */
    public function viewDocument(int $documentId, int $officerUserId): array
    {
        $document = $this->kyc->document($documentId);

        if ($document === null) {
            throw new OperationNotPermittedException('مدرک یافت نشد.');
        }

        $this->assertMayReview((int) $document['organization_id'], $officerUserId);

        $this->auditor->viewSensitive(
            subjectType: 'Document',
            subjectId: $documentId,
            fields: ['storage_path', 'file_hash', 'original_filename'],
            organizationId: (int) $document['organization_id'],
            actorId: $officerUserId,
        );

        return $document;
    }

    /**
     * Approve, reject or ask for more — always with a written note.
     */
    public function decide(
        int $organizationId,
        int $officerUserId,
        string $decision,
        string $notes,
    ): void {
        $this->assertMayReview($organizationId, $officerUserId);

        if (trim($notes) === '') {
            throw new OperationNotPermittedException('ثبت تصمیم بدون یادداشت مجاز نیست.');
        }

        $dossier = $this->kyc->dossier($organizationId);

        if ($dossier === null) {
            throw new OperationNotPermittedException('پرونده KYC برای این سازمان وجود ندارد.');
        }

        $reviewId = $this->kyc->recordDecision(
            $organizationId,
            $officerUserId,
            $decision,
            $notes,
            $dossier->automatedChecks,
        );

        // Identity owns the organisation's status, so the transition is asked
        // for, not written. A transition that is not legal from the current
        // status is simply skipped: the KYC decision still stands and the
        // mismatch is visible on the screen.
        $this->requestOrganizationTransition($organizationId, $officerUserId, $decision, $notes);

        $this->auditor->action(
            action: 'admin.kyc.'.strtolower($decision),
            subjectType: 'KycProfile',
            subjectId: $dossier->profileId,
            note: $notes,
            before: ['status' => $dossier->status],
            after: ['status' => $decision, 'review_id' => $reviewId],
            organizationId: $organizationId,
            actorId: $officerUserId,
        );
    }

    /**
     * §1.11: «هیچ کارمندی نتواند سازمان خودش یا آشنایانش را بررسی کند».
     *
     * The "acquaintances" half needs a conflict-of-interest register the
     * platform does not have yet; the half that can be enforced from data on
     * hand — the officer's own organisation — is enforced here, and the gap is
     * named rather than silently ignored.
     */
    public function assertMayReview(int $organizationId, int $officerUserId): void
    {
        $officer = $this->directory->findUser($officerUserId);

        if ($officer === null) {
            throw new OperationNotPermittedException('کاربر بررسی‌کننده یافت نشد.');
        }

        if ($officer->organizationId === $organizationId) {
            $this->auditor->denied(
                'admin.kyc.review',
                'Organization',
                $organizationId,
                'officer attempted to review their own organization',
                $officerUserId,
            );

            throw new OperationNotPermittedException(
                'بررسی پرونده سازمان متبوع خودتان مجاز نیست.'
            );
        }
    }

    private function requestOrganizationTransition(
        int $organizationId,
        int $officerUserId,
        string $decision,
        string $notes,
    ): void {
        $current = $this->organizations->currentStatus($organizationId);

        $targets = match ($decision) {
            'APPROVED' => [OrganizationStatus::VERIFIED, OrganizationStatus::ACTIVE],
            'REJECTED' => [OrganizationStatus::REJECTED],
            'INFO_REQUIRED' => [OrganizationStatus::INFO_REQUIRED],
            default => [],
        };

        foreach ($targets as $target) {
            if (! $current->canTransitionTo($target)) {
                continue;
            }

            $this->organizations->transition(
                organizationId: $organizationId,
                target: $target,
                actorUserId: $officerUserId,
                reason: $notes,
            );

            $current = $this->organizations->currentStatus($organizationId);
        }
    }
}
