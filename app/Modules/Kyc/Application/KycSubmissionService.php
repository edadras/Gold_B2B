<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Identity\Application\OrganizationStateMachine;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Events\KycSubmitted;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Kyc\Infrastructure\Models\KycProfile;
use App\Modules\Kyc\Infrastructure\Models\Signatory;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Owns the member-facing half of KYC: assembling the dossier and submitting it.
 *
 * The completeness check runs before SUBMITTED so incomplete dossiers never
 * reach the compliance queue — an officer's time is the scarce resource here.
 */
final class KycSubmissionService
{
    public function __construct(
        private readonly OrganizationStateMachine $organizationState,
    ) {}

    public function profileFor(Organization $organization): KycProfile
    {
        /** @var KycProfile $profile */
        $profile = KycProfile::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            ['status' => KycStatus::DRAFT->value],
        );

        return $profile;
    }

    /**
     * Everything still missing before the dossier can be submitted.
     *
     * @return list<string> machine-readable item keys, empty when complete
     */
    public function missingItems(Organization $organization): array
    {
        $missing = [];
        $type = $organization->type;

        $provided = Document::query()
            ->where('organization_id', $organization->id)
            ->provided()
            ->pluck('type')
            ->map(static fn (mixed $t): string => $t instanceof BackedEnum ? (string) $t->value : (string) $t)
            ->all();

        foreach (DocumentType::requiredFor($type) as $required) {
            if (! in_array($required->value, $provided, true)) {
                $missing[] = 'document:'.$required->value;
            }
        }

        $license = BusinessLicense::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('expires_at')
            ->first();

        if ($license === null) {
            $missing[] = 'business_license:missing';
        } elseif ($license->isExpired()) {
            $missing[] = 'business_license:expired';
        }

        if (! BankAccount::query()->where('organization_id', $organization->id)->exists()) {
            $missing[] = 'bank_account:missing';
        }

        if ($type === OrganizationType::LEGAL_ENTITY) {
            if ($organization->legal_id_hash === null) {
                $missing[] = 'identity:legal_id';
            }

            if ($organization->registration_no === null || $organization->registration_no === '') {
                $missing[] = 'identity:registration_no';
            }

            if (! Signatory::query()
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->exists()) {
                $missing[] = 'signatory:missing';
            }
        }

        if ($type === OrganizationType::INDIVIDUAL && $organization->national_id_hash === null) {
            $missing[] = 'identity:national_id';
        }

        return $missing;
    }

    public function isComplete(Organization $organization): bool
    {
        return $this->missingItems($organization) === [];
    }

    /**
     * DRAFT|INFO_REQUIRED → SUBMITTED, and PENDING|INFO_REQUIRED → UNDER_REVIEW
     * on the organisation itself.
     *
     * @throws OperationNotPermittedException when the dossier is incomplete
     */
    public function submit(Organization $organization, ?int $submittedByUserId = null): KycProfile
    {
        $profile = $this->profileFor($organization);

        if (! $profile->status->canTransitionTo(KycStatus::SUBMITTED)) {
            throw new InvalidStateTransitionException(
                'KycProfile', $profile->status->value, KycStatus::SUBMITTED->value,
            );
        }

        $missing = $this->missingItems($organization);

        if ($missing !== []) {
            throw new IncompleteKycException($missing);
        }

        $profile = DB::transaction(function () use ($profile): KycProfile {
            $profile->status = KycStatus::SUBMITTED;
            $profile->submitted_at = now();
            $profile->submission_count = (int) $profile->submission_count + 1;
            $profile->save();

            return $profile;
        });

        // The organisation follows the dossier into the review queue. Its own
        // state machine validates the move and writes its own event row.
        if ($organization->status->canTransitionTo(OrganizationStatus::UNDER_REVIEW)) {
            $this->organizationState->transition(
                organization: $organization,
                target: OrganizationStatus::UNDER_REVIEW,
                actorUserId: $submittedByUserId,
                reason: 'ارسال کامل مدارک KYC',
            );
        }

        Event::dispatch(new KycSubmitted(
            organizationId: (int) $organization->id,
            kycProfileId: (int) $profile->id,
            organizationType: $organization->type->value,
            submissionCount: (int) $profile->submission_count,
            submittedByUserId: $submittedByUserId,
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    /** Member pulls a submitted dossier back to edit it. */
    public function withdrawToDraft(Organization $organization): KycProfile
    {
        $profile = $this->profileFor($organization);

        if (! $profile->status->canTransitionTo(KycStatus::DRAFT)) {
            throw new InvalidStateTransitionException(
                'KycProfile', $profile->status->value, KycStatus::DRAFT->value,
            );
        }

        $profile->status = KycStatus::DRAFT;
        $profile->save();

        return $profile;
    }
}
