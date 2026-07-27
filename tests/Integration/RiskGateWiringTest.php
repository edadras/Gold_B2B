<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use App\Modules\Risk\Infrastructure\IdentityOrganizationStatusReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §11.4 checks 2 and 3 — membership status and licence validity.
 *
 * These were bound to NullOrganizationStatusReader in the running application,
 * which answers ACTIVE with no licence expiry for every organisation there has
 * ever been. That was the correct default while Identity did not exist. Once it
 * did, the binding stayed, and the consequence was that both checks passed for
 * everybody: a SUSPENDED member, a RESTRICTED one, one whose licence had
 * lapsed, all cleared the pre-trade gate exactly like a member in good
 * standing. Nothing errored — the checks simply had no opinion.
 *
 * Risk's own suite could not catch it. It installs its own reader to drive the
 * limit arithmetic, which is right for testing limits and blind to which reader
 * the application actually uses. So these tests resolve the contract from the
 * real container and assert against real Identity rows.
 */
final class RiskGateWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function the_application_uses_the_real_reader_not_the_null_one(): void
    {
        // Stated directly, because every other assertion in this file would
        // still pass with the Null reader bound — it reports ACTIVE, and ACTIVE
        // is what the happy path expects.
        $this->assertInstanceOf(
            IdentityOrganizationStatusReader::class,
            $this->app->make(OrganizationStatusReaderInterface::class),
        );
    }

    #[Test]
    public function an_active_member_reads_as_active(): void
    {
        $organization = $this->organization(OrganizationStatus::ACTIVE);

        $this->assertSame('ACTIVE', $this->reader()->statusOf((int) $organization->id));
    }

    #[Test]
    public function a_suspended_member_no_longer_passes_the_membership_check(): void
    {
        $organization = $this->organization(OrganizationStatus::SUSPENDED);

        // RiskGuard refuses anything that is not exactly 'ACTIVE'.
        $this->assertNotSame('ACTIVE', $this->reader()->statusOf((int) $organization->id));
        $this->assertSame('SUSPENDED', $this->reader()->statusOf((int) $organization->id));
    }

    #[Test]
    public function a_restricted_member_does_not_pass_either(): void
    {
        $organization = $this->organization(OrganizationStatus::RESTRICTED);

        $this->assertSame('RESTRICTED', $this->reader()->statusOf((int) $organization->id));
    }

    #[Test]
    public function an_organization_that_does_not_exist_fails_closed(): void
    {
        // The old default's real sin: not knowing, and answering ACTIVE. An id
        // the directory cannot find must stop the trade, not wave it through.
        $this->assertSame(
            IdentityOrganizationStatusReader::UNKNOWN,
            $this->reader()->statusOf(999_999),
        );
        $this->assertNotSame('ACTIVE', $this->reader()->statusOf(999_999));
    }

    #[Test]
    public function a_member_with_no_licence_recorded_is_not_treated_as_expired(): void
    {
        $organization = $this->organization(OrganizationStatus::ACTIVE);

        // §11.4: "null means no licence expiry recorded, which is never treated
        // as expired". Failing closed here would lock out every member the
        // platform has not yet collected a permit from.
        $this->assertNull($this->reader()->licenseExpiresAt((int) $organization->id));
    }

    #[Test]
    public function the_earliest_licence_expiry_is_the_one_that_counts(): void
    {
        $organization = $this->organization(OrganizationStatus::ACTIVE);

        $this->licence($organization, '2027-09-01', 'VALID', 'BL-LATE');
        $this->licence($organization, '2026-03-15', 'VALID', 'BL-EARLY');

        // A trader holding two permits is only as licensed as the one that
        // lapses first.
        $this->assertSame(
            '2026-03-15',
            $this->reader()->licenseExpiresAt((int) $organization->id)?->format('Y-m-d'),
        );
    }

    #[Test]
    public function a_revoked_licence_does_not_set_the_expiry_date(): void
    {
        $organization = $this->organization(OrganizationStatus::ACTIVE);

        $this->licence($organization, '2020-01-01', 'REVOKED', 'BL-GONE');
        $this->licence($organization, '2027-09-01', 'VALID', 'BL-GOOD');

        // A revoked permit's date means nothing — revocation is handled through
        // the member's status. Reading it would expire a validly licensed
        // member on the strength of a permit they no longer hold.
        $this->assertSame(
            '2027-09-01',
            $this->reader()->licenseExpiresAt((int) $organization->id)?->format('Y-m-d'),
        );
    }

    #[Test]
    public function an_expired_licence_is_reported_as_past(): void
    {
        $organization = $this->organization(OrganizationStatus::ACTIVE);

        $this->licence($organization, '2020-01-01', 'EXPIRED', 'BL-OLD');

        $expiry = $this->reader()->licenseExpiresAt((int) $organization->id);

        $this->assertNotNull($expiry);
        $this->assertTrue($expiry->isPast(), 'RiskGuard refuses on exactly this');
    }

    private function reader(): OrganizationStatusReaderInterface
    {
        return $this->app->make(OrganizationStatusReaderInterface::class);
    }

    private function organization(OrganizationStatus $status): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::factory()->create([
            'status' => $status,
            'activated_at' => $status === OrganizationStatus::ACTIVE ? now() : null,
        ]);

        return $organization;
    }

    private function licence(Organization $organization, string $expiresAt, string $status, string $number): void
    {
        DB::table('business_licenses')->insert([
            'organization_id' => $organization->id,
            'license_no' => $number,
            'issuing_union' => 'اتحادیه طلا و جواهر تهران',
            'issued_at' => CarbonImmutable::parse($expiresAt)->subYear(),
            'expires_at' => $expiresAt,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
