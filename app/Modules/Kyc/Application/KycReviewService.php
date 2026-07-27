<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Contracts\OrganizationSnapshot;
use App\Modules\Identity\Contracts\UserSnapshot;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Validators\IbanValidator;
use App\Modules\Kyc\Domain\KycDecision;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Events\KycApproved;
use App\Modules\Kyc\Events\KycInfoRequired;
use App\Modules\Kyc\Events\KycRejected;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Infrastructure\Models\KycProfile;
use App\Modules\Kyc\Infrastructure\Models\KycReview;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * The compliance officer's side of KYC (docs/03-domain/01-identity-kyc.md §1.5).
 *
 * Three hard rules, all enforced here:
 *   1. every decision carries a written note — an empty note is a rejection of
 *      the decision, not of the member;
 *   2. an officer may never review their own organisation;
 *   3. approval moves the organisation VERIFIED → ACTIVE, and that transition
 *      is what triggers ledger account creation, via OrganizationActivated.
 *
 * Organisations and officers are addressed by id: reads go through
 * IdentityDirectory, actions through OrganizationLifecycle, so no Identity
 * model or application service crosses the boundary.
 */
final class KycReviewService
{
    public function __construct(
        private readonly IdentityDirectory $directory,
        private readonly OrganizationLifecycle $organizations,
        private readonly KycSubmissionService $submissions,
    ) {}

    /** SUBMITTED → IN_REVIEW: the officer picks the dossier off the queue. */
    public function claim(int $organizationId, int $officerUserId): KycProfile
    {
        $this->assertOfficerMayReview($organizationId, $officerUserId);

        $profile = $this->submissions->profileFor($organizationId);

        if (! $profile->status->canTransitionTo(KycStatus::IN_REVIEW)) {
            throw new InvalidStateTransitionException(
                'KycProfile', $profile->status->value, KycStatus::IN_REVIEW->value,
            );
        }

        $profile->status = KycStatus::IN_REVIEW;
        $profile->review_started_at = now();
        $profile->save();

        return $profile;
    }

    /**
     * Approve: dossier → APPROVED, organisation → VERIFIED → ACTIVE.
     *
     * The double hop is deliberate. VERIFIED means "compliance is satisfied";
     * ACTIVE means "the ledger accounts exist and limits are assigned". Keeping
     * them separate leaves a visible state for the automatic step to fail in.
     */
    public function approve(int $organizationId, int $officerUserId, string $notes): KycProfile
    {
        $this->assertNoteProvided($notes);
        $this->assertOfficerMayReview($organizationId, $officerUserId);

        $organization = $this->snapshot($organizationId);
        $profile = $this->submissions->profileFor($organizationId);
        $this->assertReviewable($profile, KycStatus::APPROVED);

        $checks = $this->automatedChecks($organizationId);
        $nextReviewDue = now()
            ->addMonths($organization->organizationRiskLevel()->reviewIntervalMonths())
            ->toDateString();

        DB::transaction(function () use ($profile, $organizationId, $officerUserId, $notes, $checks, $nextReviewDue): void {
            $profile->status = KycStatus::APPROVED;
            $profile->approved_at = now();
            $profile->approved_by_user_id = $officerUserId;
            $profile->last_decision_note = $notes;
            $profile->next_review_due_at = $nextReviewDue;
            $profile->save();

            $this->recordReview($profile, $organizationId, $officerUserId, KycDecision::APPROVED, $notes, $checks, []);
        });

        // Outside the transaction: each transition dispatches events of its own,
        // and events must never fire from inside one (AGENT_BRIEF rule 3).
        if ($this->organizations->currentStatus($organizationId)->canTransitionTo(OrganizationStatus::VERIFIED)) {
            $this->organizations->transition(
                organizationId: $organizationId,
                target: OrganizationStatus::VERIFIED,
                actorUserId: $officerUserId,
                reason: 'تأیید افسر انطباق',
            );
        }

        // VERIFIED → ACTIVE is automatic (docs §1.2). OrganizationActivated is
        // what Ledger listens for to create the member's accounts.
        if ($this->organizations->currentStatus($organizationId)->canTransitionTo(OrganizationStatus::ACTIVE)) {
            $this->organizations->transitionBySystem(
                organizationId: $organizationId,
                target: OrganizationStatus::ACTIVE,
                reason: 'ایجاد خودکار حساب دفتر پس از تأیید KYC',
            );
        }

        Event::dispatch(new KycApproved(
            organizationId: $organizationId,
            kycProfileId: (int) $profile->id,
            reviewerUserId: $officerUserId,
            notes: $notes,
            nextReviewDueAt: $nextReviewDue,
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    public function reject(int $organizationId, int $officerUserId, string $notes): KycProfile
    {
        $this->assertNoteProvided($notes);
        $this->assertOfficerMayReview($organizationId, $officerUserId);

        $profile = $this->submissions->profileFor($organizationId);
        $this->assertReviewable($profile, KycStatus::REJECTED);

        $checks = $this->automatedChecks($organizationId);

        DB::transaction(function () use ($profile, $organizationId, $officerUserId, $notes, $checks): void {
            $profile->status = KycStatus::REJECTED;
            $profile->rejected_at = now();
            $profile->last_decision_note = $notes;
            $profile->save();

            $this->recordReview($profile, $organizationId, $officerUserId, KycDecision::REJECTED, $notes, $checks, []);
        });

        if ($this->organizations->currentStatus($organizationId)->canTransitionTo(OrganizationStatus::REJECTED)) {
            $this->organizations->transition(
                organizationId: $organizationId,
                target: OrganizationStatus::REJECTED,
                actorUserId: $officerUserId,
                reason: $notes,
            );
        }

        Event::dispatch(new KycRejected(
            organizationId: $organizationId,
            kycProfileId: (int) $profile->id,
            reviewerUserId: $officerUserId,
            notes: $notes,
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    /**
     * Ask for more: dossier → INFO_REQUIRED, organisation → INFO_REQUIRED.
     *
     * @param  list<string>  $missingItems  specific defects, so the notification
     *                                      can say exactly what is wrong
     */
    public function requestInfo(
        int $organizationId,
        int $officerUserId,
        string $notes,
        array $missingItems = [],
    ): KycProfile {
        $this->assertNoteProvided($notes);
        $this->assertOfficerMayReview($organizationId, $officerUserId);

        $profile = $this->submissions->profileFor($organizationId);
        $this->assertReviewable($profile, KycStatus::INFO_REQUIRED);

        $checks = $this->automatedChecks($organizationId);

        if ($missingItems === []) {
            $missingItems = $this->submissions->missingItems($organizationId);
        }

        DB::transaction(function () use ($profile, $organizationId, $officerUserId, $notes, $checks, $missingItems): void {
            $profile->status = KycStatus::INFO_REQUIRED;
            $profile->last_decision_note = $notes;
            $profile->save();

            $this->recordReview(
                $profile, $organizationId, $officerUserId, KycDecision::INFO_REQUIRED, $notes, $checks, $missingItems
            );
        });

        if ($this->organizations->currentStatus($organizationId)->canTransitionTo(OrganizationStatus::INFO_REQUIRED)) {
            $this->organizations->transition(
                organizationId: $organizationId,
                target: OrganizationStatus::INFO_REQUIRED,
                actorUserId: $officerUserId,
                reason: $notes,
            );
        }

        Event::dispatch(new KycInfoRequired(
            organizationId: $organizationId,
            kycProfileId: (int) $profile->id,
            reviewerUserId: $officerUserId,
            notes: $notes,
            missingItems: array_values($missingItems),
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    /**
     * The machine verdicts shown on the review screen (docs §1.5). Stored with
     * the decision so a later audit sees exactly what the officer saw.
     *
     * The identifier verdicts come from Identity, which is the only module that
     * may decrypt them; the licence and IBAN checks are over Kyc's own tables.
     *
     * @return array<string, mixed>
     */
    public function automatedChecks(int $organizationId): array
    {
        $identity = $this->directory->organizationIdentityChecks($organizationId);

        $license = BusinessLicense::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('expires_at')
            ->first();

        $ibansValid = BankAccount::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->every(static fn (BankAccount $a): bool => IbanValidator::isValid((string) ($a->iban_enc ?? '')));

        return [
            'national_id_valid' => $identity?->nationalIdValid,
            'legal_id_valid' => $identity?->legalIdValid,
            'duplicate_national_id' => $identity?->duplicateNationalId ?? false,
            'duplicate_legal_id' => $identity?->duplicateLegalId ?? false,
            'iban_valid' => $ibansValid,
            'license_present' => $license !== null,
            'license_days_remaining' => $license?->daysUntilExpiry(),
            // Sanctions screening is the Risk/AML module's job; it publishes its
            // verdict onto the review screen through its own contract.
            'sanctions_screened' => null,
        ];
    }

    /**
     * "افسر انطباق نمی‌تواند سازمان خودش را بررسی کند" — docs §1.5.
     *
     * Checked before anything else so a conflicted officer cannot even see the
     * automated results of their own file.
     */
    private function assertOfficerMayReview(int $organizationId, int $officerUserId): void
    {
        $officer = $this->officer($officerUserId);

        if ($officer->organizationId === $organizationId) {
            throw new OperationNotPermittedException('officer_cannot_review_own_organization');
        }

        // Platform-scoped permission: the tenancy leg of the check is satisfied
        // by the officer's own organisation, not the member under review.
        $permitted = $this->directory->userMayActOn(
            $officerUserId,
            Permission::PLATFORM_KYC_REVIEW->value,
            $officer->organizationId,
        );

        if (! $permitted) {
            throw new OperationNotPermittedException(
                'missing_permission:'.Permission::PLATFORM_KYC_REVIEW->value
            );
        }
    }

    private function assertNoteProvided(string $notes): void
    {
        if (trim($notes) === '') {
            throw new OperationNotPermittedException('kyc_decision_requires_a_written_note');
        }
    }

    private function assertReviewable(KycProfile $profile, KycStatus $target): void
    {
        // Officers habitually decide straight from the queue without clicking
        // "claim" first, so SUBMITTED is auto-promoted to IN_REVIEW.
        if ($profile->status === KycStatus::SUBMITTED) {
            $profile->status = KycStatus::IN_REVIEW;
            $profile->review_started_at ??= now();
            $profile->save();
        }

        if (! $profile->status->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                'KycProfile', $profile->status->value, $target->value,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $checks
     * @param  list<string>  $missingItems
     */
    private function recordReview(
        KycProfile $profile,
        int $organizationId,
        int $officerUserId,
        KycDecision $decision,
        string $notes,
        array $checks,
        array $missingItems,
    ): void {
        KycReview::query()->create([
            'organization_id' => $organizationId,
            'kyc_profile_id' => $profile->id,
            'reviewer_user_id' => $officerUserId,
            'decision' => $decision->value,
            'notes' => $notes,
            'automated_checks' => $checks,
            'missing_items' => $missingItems === [] ? null : array_values($missingItems),
            'reviewed_at' => now(),
        ]);
    }

    private function snapshot(int $organizationId): OrganizationSnapshot
    {
        $organization = $this->directory->findOrganization($organizationId);

        if ($organization === null) {
            throw new InvalidArgumentException("Unknown organization: {$organizationId}");
        }

        return $organization;
    }

    private function officer(int $officerUserId): UserSnapshot
    {
        $officer = $this->directory->findUser($officerUserId);

        if ($officer === null) {
            throw new OperationNotPermittedException('unknown_reviewer');
        }

        return $officer;
    }
}
