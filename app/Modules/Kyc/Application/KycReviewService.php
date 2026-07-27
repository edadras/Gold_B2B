<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Identity\Application\OrganizationStateMachine;
use App\Modules\Identity\Application\PermissionChecker;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Validators\IbanValidator;
use App\Modules\Identity\Domain\Validators\LegalIdValidator;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
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

/**
 * The compliance officer's side of KYC (docs/03-domain/01-identity-kyc.md §1.5).
 *
 * Three hard rules, all enforced here:
 *   1. every decision carries a written note — an empty note is a rejection of
 *      the decision, not of the member;
 *   2. an officer may never review their own organisation;
 *   3. approval moves the organisation VERIFIED → ACTIVE, and that transition
 *      is what triggers ledger account creation, via OrganizationActivated.
 */
final class KycReviewService
{
    public function __construct(
        private readonly OrganizationStateMachine $organizationState,
        private readonly PermissionChecker $permissions,
        private readonly KycSubmissionService $submissions,
    ) {}

    /** SUBMITTED → IN_REVIEW: the officer picks the dossier off the queue. */
    public function claim(Organization $organization, User $officer): KycProfile
    {
        $this->assertOfficerMayReview($organization, $officer);

        $profile = $this->submissions->profileFor($organization);

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
    public function approve(Organization $organization, User $officer, string $notes): KycProfile
    {
        $this->assertNoteProvided($notes);
        $this->assertOfficerMayReview($organization, $officer);

        $profile = $this->submissions->profileFor($organization);
        $this->assertReviewable($profile, KycStatus::APPROVED);

        $checks = $this->automatedChecks($organization);
        $nextReviewDue = now()->addMonths($organization->risk_level->reviewIntervalMonths())->toDateString();

        DB::transaction(function () use ($profile, $organization, $officer, $notes, $checks, $nextReviewDue): void {
            $profile->status = KycStatus::APPROVED;
            $profile->approved_at = now();
            $profile->approved_by_user_id = $officer->id;
            $profile->last_decision_note = $notes;
            $profile->next_review_due_at = $nextReviewDue;
            $profile->save();

            $this->recordReview($profile, $organization, $officer, KycDecision::APPROVED, $notes, $checks, []);
        });

        // Outside the transaction: each of these dispatches events of its own.
        if ($organization->status->canTransitionTo(OrganizationStatus::VERIFIED)) {
            $organization = $this->organizationState->transition(
                organization: $organization,
                target: OrganizationStatus::VERIFIED,
                actorUserId: (int) $officer->id,
                reason: 'تأیید افسر انطباق',
            );
        }

        // VERIFIED → ACTIVE is automatic (docs §1.2). OrganizationActivated is
        // what Ledger listens for to create the member's accounts.
        if ($organization->status->canTransitionTo(OrganizationStatus::ACTIVE)) {
            $this->organizationState->transitionBySystem(
                organization: $organization,
                target: OrganizationStatus::ACTIVE,
                reason: 'ایجاد خودکار حساب دفتر پس از تأیید KYC',
            );
        }

        Event::dispatch(new KycApproved(
            organizationId: (int) $organization->id,
            kycProfileId: (int) $profile->id,
            reviewerUserId: (int) $officer->id,
            notes: $notes,
            nextReviewDueAt: $nextReviewDue,
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    public function reject(Organization $organization, User $officer, string $notes): KycProfile
    {
        $this->assertNoteProvided($notes);
        $this->assertOfficerMayReview($organization, $officer);

        $profile = $this->submissions->profileFor($organization);
        $this->assertReviewable($profile, KycStatus::REJECTED);

        $checks = $this->automatedChecks($organization);

        DB::transaction(function () use ($profile, $organization, $officer, $notes, $checks): void {
            $profile->status = KycStatus::REJECTED;
            $profile->rejected_at = now();
            $profile->last_decision_note = $notes;
            $profile->save();

            $this->recordReview($profile, $organization, $officer, KycDecision::REJECTED, $notes, $checks, []);
        });

        if ($organization->status->canTransitionTo(OrganizationStatus::REJECTED)) {
            $this->organizationState->transition(
                organization: $organization,
                target: OrganizationStatus::REJECTED,
                actorUserId: (int) $officer->id,
                reason: $notes,
            );
        }

        Event::dispatch(new KycRejected(
            organizationId: (int) $organization->id,
            kycProfileId: (int) $profile->id,
            reviewerUserId: (int) $officer->id,
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
        Organization $organization,
        User $officer,
        string $notes,
        array $missingItems = [],
    ): KycProfile {
        $this->assertNoteProvided($notes);
        $this->assertOfficerMayReview($organization, $officer);

        $profile = $this->submissions->profileFor($organization);
        $this->assertReviewable($profile, KycStatus::INFO_REQUIRED);

        $checks = $this->automatedChecks($organization);

        if ($missingItems === []) {
            $missingItems = $this->submissions->missingItems($organization);
        }

        DB::transaction(function () use ($profile, $organization, $officer, $notes, $checks, $missingItems): void {
            $profile->status = KycStatus::INFO_REQUIRED;
            $profile->last_decision_note = $notes;
            $profile->save();

            $this->recordReview(
                $profile, $organization, $officer, KycDecision::INFO_REQUIRED, $notes, $checks, $missingItems
            );
        });

        if ($organization->status->canTransitionTo(OrganizationStatus::INFO_REQUIRED)) {
            $this->organizationState->transition(
                organization: $organization,
                target: OrganizationStatus::INFO_REQUIRED,
                actorUserId: (int) $officer->id,
                reason: $notes,
            );
        }

        Event::dispatch(new KycInfoRequired(
            organizationId: (int) $organization->id,
            kycProfileId: (int) $profile->id,
            reviewerUserId: (int) $officer->id,
            notes: $notes,
            missingItems: array_values($missingItems),
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    /**
     * The machine verdicts shown on the review screen (docs §1.5). Stored with
     * the decision so a later audit sees what the officer saw.
     *
     * @return array<string, mixed>
     */
    public function automatedChecks(Organization $organization): array
    {
        $nationalId = (string) ($organization->national_id_enc ?? '');
        $legalId = (string) ($organization->legal_id_enc ?? '');

        $license = BusinessLicense::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('expires_at')
            ->first();

        $ibansValid = BankAccount::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->every(static fn (BankAccount $a): bool => IbanValidator::isValid((string) ($a->iban_enc ?? '')));

        $duplicateNationalId = $nationalId !== '' && Organization::query()
            ->where('national_id_hash', $organization->national_id_hash)
            ->whereKeyNot($organization->id)
            ->exists();

        return [
            'national_id_valid' => $nationalId === '' ? null : NationalIdValidator::isValid($nationalId),
            'legal_id_valid' => $legalId === '' ? null : LegalIdValidator::isValid($legalId),
            'iban_valid' => $ibansValid,
            'license_present' => $license !== null,
            'license_days_remaining' => $license?->daysUntilExpiry(),
            'duplicate_national_id' => $duplicateNationalId,
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
    private function assertOfficerMayReview(Organization $organization, User $officer): void
    {
        if ((int) $officer->organization_id === (int) $organization->id) {
            throw new OperationNotPermittedException('officer_cannot_review_own_organization');
        }

        if ($this->permissions->denies($officer, Permission::PLATFORM_KYC_REVIEW)) {
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
        Organization $organization,
        User $officer,
        KycDecision $decision,
        string $notes,
        array $checks,
        array $missingItems,
    ): void {
        KycReview::query()->create([
            'organization_id' => $organization->id,
            'kyc_profile_id' => $profile->id,
            'reviewer_user_id' => $officer->id,
            'decision' => $decision->value,
            'notes' => $notes,
            'automated_checks' => $checks,
            'missing_items' => $missingItems === [] ? null : array_values($missingItems),
            'reviewed_at' => now(),
        ]);
    }
}
