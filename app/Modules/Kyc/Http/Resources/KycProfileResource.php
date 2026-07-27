<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Resources;

use App\Modules\Kyc\Infrastructure\Models\KycProfile;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * The member's own KYC dossier — `GET /organization/kyc`.
 *
 * `missing_items` is the machine-readable list the onboarding screen ticks
 * off; it is computed by KycSubmissionService and handed in rather than
 * derived here, because a resource must not run queries.
 *
 * The officer's side of the dossier (review notes, the reviewer's identity,
 * the internal risk band) is deliberately absent: this endpoint is read by the
 * member, and `last_decision_note` is written for compliance, not for them.
 *
 * @mixin KycProfile
 */
final class KycProfileResource extends ApiResource
{
    /** @param list<string> $missingItems */
    public function __construct(
        mixed $resource,
        private readonly array $missingItems = [],
        private readonly bool $complete = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var KycProfile $profile */
        $profile = $this->resource;

        return [
            'id' => (int) $profile->id,
            'organization_id' => (int) $profile->organization_id,
            'status' => $profile->status->value,
            'is_complete' => $this->complete,
            'missing_items' => $this->missingItems,
            'is_editable' => $profile->status->isEditableByMember(),
            'is_awaiting_officer' => $profile->status->isAwaitingOfficer(),
            'economic_code' => $profile->economic_code,
            'tax_tracking_code' => $profile->tax_tracking_code,
            'tax_registered' => (bool) $profile->tax_registered,
            'submission_count' => (int) $profile->submission_count,
            'submitted_at' => Display::iso($profile->submitted_at),
            'approved_at' => Display::iso($profile->approved_at),
            'rejected_at' => Display::iso($profile->rejected_at),
            'next_review_due_at' => $profile->next_review_due_at?->toDateString(),
        ] + $this->display($request, [
            'submitted_at_jalali' => Display::jalali($profile->submitted_at),
            'approved_at_jalali' => Display::jalali($profile->approved_at),
            'rejected_at_jalali' => Display::jalali($profile->rejected_at),
            'next_review_due_at_jalali' => Display::jalali($profile->next_review_due_at, withTime: false),
        ]);
    }
}
