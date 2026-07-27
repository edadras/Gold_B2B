<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Contracts\OrganizationSnapshot;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Events\KycSubmitted;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Kyc\Infrastructure\Models\KycProfile;
use App\Modules\Kyc\Infrastructure\Models\Signatory;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * Owns the member-facing half of KYC: assembling the dossier and submitting it.
 *
 * The completeness check runs before SUBMITTED so incomplete dossiers never
 * reach the compliance queue — an officer's time is the scarce resource here.
 *
 * Everything is addressed by organization id. Kyc reads member data through
 * IdentityDirectory (snapshot DTOs) and acts through OrganizationLifecycle; it
 * never holds an Identity Eloquent model — docs/02-architecture/02-modules.md §2.3.
 */
final class KycSubmissionService
{
    public function __construct(
        private readonly IdentityDirectory $directory,
        private readonly OrganizationLifecycle $organizations,
    ) {}

    public function profileFor(int $organizationId): KycProfile
    {
        /** @var KycProfile $profile */
        $profile = KycProfile::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            ['status' => KycStatus::DRAFT->value],
        );

        return $profile;
    }

    /**
     * Everything still missing before the dossier can be submitted.
     *
     * @return list<string> machine-readable item keys, empty when complete
     */
    public function missingItems(int $organizationId): array
    {
        $organization = $this->snapshot($organizationId);
        $type = $organization->organizationType();

        $missing = [];

        $provided = Document::query()
            ->where('organization_id', $organizationId)
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
            ->where('organization_id', $organizationId)
            ->orderByDesc('expires_at')
            ->first();

        if ($license === null) {
            $missing[] = 'business_license:missing';
        } elseif ($license->isExpired()) {
            $missing[] = 'business_license:expired';
        }

        if (! BankAccount::query()->where('organization_id', $organizationId)->exists()) {
            $missing[] = 'bank_account:missing';
        }

        if ($type === OrganizationType::LEGAL_ENTITY) {
            // The snapshot answers "is an identifier on file?" without ever
            // handing Kyc the identifier or its blind index.
            if (! $organization->hasLegalId) {
                $missing[] = 'identity:legal_id';
            }

            if ($organization->registrationNo === null || $organization->registrationNo === '') {
                $missing[] = 'identity:registration_no';
            }

            if (! Signatory::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->exists()) {
                $missing[] = 'signatory:missing';
            }
        }

        if ($type === OrganizationType::INDIVIDUAL && ! $organization->hasNationalId) {
            $missing[] = 'identity:national_id';
        }

        return $missing;
    }

    public function isComplete(int $organizationId): bool
    {
        return $this->missingItems($organizationId) === [];
    }

    /**
     * DRAFT|INFO_REQUIRED → SUBMITTED, and PENDING|INFO_REQUIRED → UNDER_REVIEW
     * on the organisation itself.
     *
     * @throws IncompleteKycException when documents are still outstanding
     */
    public function submit(int $organizationId, ?int $submittedByUserId = null): KycProfile
    {
        $organization = $this->snapshot($organizationId);
        $profile = $this->profileFor($organizationId);

        if (! $profile->status->canTransitionTo(KycStatus::SUBMITTED)) {
            throw new InvalidStateTransitionException(
                'KycProfile', $profile->status->value, KycStatus::SUBMITTED->value,
            );
        }

        $missing = $this->missingItems($organizationId);

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

        // The organisation follows the dossier into the review queue. Identity's
        // own state machine validates the move and writes its own event row.
        if ($organization->organizationStatus()->canTransitionTo(OrganizationStatus::UNDER_REVIEW)) {
            $this->organizations->transition(
                organizationId: $organizationId,
                target: OrganizationStatus::UNDER_REVIEW,
                actorUserId: $submittedByUserId,
                reason: 'ارسال کامل مدارک KYC',
            );
        }

        Event::dispatch(new KycSubmitted(
            organizationId: $organizationId,
            kycProfileId: (int) $profile->id,
            organizationType: $organization->type,
            submissionCount: (int) $profile->submission_count,
            submittedByUserId: $submittedByUserId,
            occurredAt: now()->toIso8601String(),
        ));

        return $profile;
    }

    /** Member pulls a submitted dossier back to edit it. */
    public function withdrawToDraft(int $organizationId): KycProfile
    {
        $profile = $this->profileFor($organizationId);

        if (! $profile->status->canTransitionTo(KycStatus::DRAFT)) {
            throw new InvalidStateTransitionException(
                'KycProfile', $profile->status->value, KycStatus::DRAFT->value,
            );
        }

        $profile->status = KycStatus::DRAFT;
        $profile->save();

        return $profile;
    }

    /** @throws InvalidArgumentException when the member does not exist */
    private function snapshot(int $organizationId): OrganizationSnapshot
    {
        $organization = $this->directory->findOrganization($organizationId);

        if ($organization === null) {
            throw new InvalidArgumentException("Unknown organization: {$organizationId}");
        }

        return $organization;
    }
}
