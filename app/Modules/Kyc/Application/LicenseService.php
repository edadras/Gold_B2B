<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Kyc\Domain\LicenseStatus;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Registering and listing the guild trading licence (جواز کسب).
 *
 * ADDED FOR THE HTTP LAYER. `business_licenses` had a model, an expiry ladder
 * (LicenseExpiryService) and a completeness check (KycSubmissionService), but
 * nothing that *creates* a row — the seeded fixtures wrote them directly. A
 * controller may not do that: `POST /organization/licenses` has to decide the
 * initial status and translate the (organization, license_no) uniqueness
 * violation into a documented error, and neither belongs in an HTTP class.
 *
 * A renewal is a new row, never an update — the audit trail has to be able to
 * answer "which licence was valid on the day of that trade?"
 * (docs/03-domain/01-identity-kyc.md §1.6).
 */
final class LicenseService
{
    /**
     * The member's licences, newest expiry first — that is the one the UI
     * shows as current.
     *
     * @return list<BusinessLicense>
     */
    public function forOrganization(int $organizationId): array
    {
        return BusinessLicense::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('expires_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function find(int $licenseId): ?BusinessLicense
    {
        /** @var BusinessLicense|null */
        return BusinessLicense::query()->find($licenseId);
    }

    /**
     * Record a licence the member has uploaded.
     *
     * The status is derived, never taken from the request: a member must not
     * be able to declare its own licence VALID. It starts PENDING (a
     * compliance officer verifies it against the union register) unless the
     * dates already say otherwise.
     *
     * @param  array{
     *     license_no: string,
     *     issuing_union: string,
     *     issued_at: string,
     *     expires_at: string,
     *     activity_type?: string|null,
     *     premises_address?: string|null,
     *     document_id?: int|null,
     * }  $attributes
     *
     * @throws KycOperationException when the licence number is already on file
     */
    public function register(int $organizationId, array $attributes, ?Carbon $asOf = null): BusinessLicense
    {
        $asOf ??= Carbon::now();

        $expiresAt = Carbon::parse($attributes['expires_at'])->startOfDay();

        try {
            /** @var BusinessLicense $license */
            $license = BusinessLicense::query()->create([
                'organization_id' => $organizationId,
                'license_no' => $attributes['license_no'],
                'issuing_union' => $attributes['issuing_union'],
                'activity_type' => $attributes['activity_type'] ?? null,
                'premises_address' => $attributes['premises_address'] ?? null,
                'issued_at' => Carbon::parse($attributes['issued_at'])->startOfDay(),
                'expires_at' => $expiresAt,
                'document_id' => $attributes['document_id'] ?? null,
                'status' => $this->initialStatus($expiresAt, $asOf)->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw KycOperationException::duplicateLicense((string) $attributes['license_no']);
        }

        return $license;
    }

    /**
     * PENDING is the normal entry point; a licence that is already past its
     * date is booked EXPIRED so it can never satisfy the completeness check.
     */
    private function initialStatus(Carbon $expiresAt, Carbon $asOf): LicenseStatus
    {
        $daysLeft = (int) $asOf->copy()->startOfDay()->diffInDays($expiresAt, false);

        return $daysLeft < 0 ? LicenseStatus::EXPIRED : LicenseStatus::PENDING;
    }
}
