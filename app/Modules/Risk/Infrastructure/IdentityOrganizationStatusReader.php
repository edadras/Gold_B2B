<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checks 2 and 3 of §11.4, answered by the module that actually knows.
 *
 * NullOrganizationStatusReader reported ACTIVE for every organisation. That was
 * the right default while Identity did not exist — inventing a restrictive
 * answer would have locked everyone out — but it stayed bound after Identity
 * arrived, and the effect was that the pre-trade gate's membership check passed
 * for every member on the platform. A SUSPENDED or RESTRICTED organisation, one
 * whose licence had expired, cleared the risk guard exactly like a member in
 * good standing. Nothing failed; the check simply had no opinion.
 *
 * Status comes from Identity's published directory. Licence expiry does not:
 * licences are Kyc's, and Risk may not depend on Kyc (see the module dependency
 * graph). It is read from the table behind a Schema guard, which keeps a
 * Kyc-less deployment answering "no expiry recorded" — and §11.4 already treats
 * that as "not expired" rather than as a refusal.
 *
 * FAILING CLOSED ON STATUS IS DELIBERATE. An organisation the directory cannot
 * find reads as UNKNOWN, which is not 'ACTIVE', which stops the trade. The
 * previous default was the opposite and that is precisely what went wrong.
 */
final class IdentityOrganizationStatusReader implements OrganizationStatusReaderInterface
{
    /** Not a real OrganizationStatus, and deliberately so: nothing accepts it. */
    public const UNKNOWN = 'UNKNOWN';

    /**
     * Whether the licences table exists, worked out once per request.
     *
     * Schema introspection is a query against information_schema and this runs
     * on the pre-trade gate — the hottest write path on the platform, hit once
     * per order. Asking every time turns a deployment-shape question that
     * cannot change mid-request into per-order overhead.
     */
    private ?bool $hasLicenceTable = null;

    public function __construct(private readonly IdentityDirectory $identity) {}

    public function statusOf(int $organizationId): string
    {
        return $this->identity->findOrganization($organizationId)?->status ?? self::UNKNOWN;
    }

    public function licenseExpiresAt(int $organizationId): ?CarbonImmutable
    {
        $this->hasLicenceTable ??= Schema::hasTable('business_licenses');

        if ($this->hasLicenceTable === false) {
            return null;
        }

        // The earliest expiry across the licences that are actually in force: a
        // trader who holds two permits is only as licensed as the one that
        // lapses first.
        //
        // PENDING and REVOKED are excluded because neither is a licence whose
        // expiry date means anything — a revoked permit is already dealt with
        // by the member's status, and a pending one has not started. What is
        // left (VALID, EXPIRING_SOON, EXPIRED) is exactly the set where the
        // date decides.
        $expiry = DB::table('business_licenses')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['VALID', 'EXPIRING_SOON', 'EXPIRED'])
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->value('expires_at');

        return $expiry === null ? null : CarbonImmutable::parse((string) $expiry);
    }
}
