<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Feature;

use App\Modules\Admin\Application\KycQueueService;
use App\Modules\Admin\Tests\AdminTestCase;
use App\Modules\Identity\Domain\Role;
use App\Modules\Shared\Exceptions\DomainException;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The KYC queue and review page (§1.8), and the two §1.11 rules that guard it:
 * an officer may not review their own organisation, and every read of sensitive
 * data leaves an audit row.
 */
final class KycReviewTest extends AdminTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_queue_lists_submitted_dossiers_oldest_first(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);

        $newer = $this->memberOrganization('طلافروشی جدید');
        $older = $this->memberOrganization('طلافروشی قدیمی');

        $this->createProfile((int) $newer->id, 'SUBMITTED', now()->subHour());
        $this->createProfile((int) $older->id, 'SUBMITTED', now()->subDay());

        $response = $this->actingAs($officer)->get('/admin/kyc');

        $response->assertOk();
        $response->assertSeeInOrder(['طلافروشی قدیمی', 'طلافروشی جدید']);
    }

    #[Test]
    public function a_compliance_officer_cannot_open_the_review_page_for_their_own_organization(): void
    {
        $this->seedRoles();

        // An officer whose staff account sits inside a member organisation is
        // exactly the conflict of interest §1.11 forbids.
        $ownOrganization = $this->memberOrganization('طلافروشی خودی');
        $officer = $this->makeUser($ownOrganization, [Role::COMPLIANCE_OFFICER]);

        $this->createProfile((int) $ownOrganization->id, 'SUBMITTED', now());

        $service = app(KycQueueService::class);

        try {
            $service->openForReview((int) $ownOrganization->id, (int) $officer->id);
            $this->fail('An officer opened their own organization for review.');
        } catch (DomainException) {
            // expected
        }

        // The refusal is itself recorded — a blocked attempt is information.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.kyc.review',
            'result' => 'denied',
            'subject_id' => $ownOrganization->id,
        ]);

        // And no sensitive-view row was written, because nothing was revealed.
        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->where('action', 'admin.view_sensitive')
                ->where('actor_id', $officer->id)
                ->count(),
        );
    }

    #[Test]
    public function a_compliance_officer_can_review_another_organization(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);
        $member = $this->memberOrganization();

        $this->createProfile((int) $member->id, 'SUBMITTED', now());

        $this->actingAs($officer)
            ->get('/admin/kyc/'.$member->id)
            ->assertOk()
            ->assertSee('بررسی‌های خودکار');
    }

    #[Test]
    public function viewing_a_kyc_document_writes_an_audit_row(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);
        $member = $this->memberOrganization();

        $this->createProfile((int) $member->id, 'SUBMITTED', now());
        $documentId = $this->createDocument((int) $member->id);

        $before = DB::table('audit_logs')->where('action', 'admin.view_sensitive')->count();

        $this->actingAs($officer)
            ->get("/admin/kyc/{$member->id}/documents/{$documentId}")
            ->assertOk();

        $rows = DB::table('audit_logs')
            ->where('action', 'admin.view_sensitive')
            ->where('subject_type', 'Document')
            ->where('subject_id', $documentId)
            ->get();

        $this->assertCount(1, $rows, 'Opening a KYC document must write exactly one sensitive-view audit row.');
        $this->assertSame((int) $officer->id, (int) $rows[0]->actor_id);
        $this->assertSame('platform_staff', $rows[0]->actor_type);
        $this->assertStringContainsString('file_hash', (string) $rows[0]->metadata);

        $this->assertGreaterThan(
            $before,
            DB::table('audit_logs')->where('action', 'admin.view_sensitive')->count(),
        );
    }

    #[Test]
    public function a_decision_without_a_note_is_rejected_at_the_edge(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);
        $member = $this->memberOrganization();

        $this->createProfile((int) $member->id, 'SUBMITTED', now());

        $this->actingAs($officer)
            ->post("/admin/kyc/{$member->id}/decision", ['decision' => 'APPROVED', 'notes' => ''])
            ->assertSessionHasErrors('notes');

        $this->assertSame('SUBMITTED', DB::table('kyc_profiles')->where('organization_id', $member->id)->value('status'));
    }

    #[Test]
    public function an_approval_records_the_review_and_audits_it(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);
        $member = $this->memberOrganization();

        $this->createProfile((int) $member->id, 'SUBMITTED', now());

        $this->actingAs($officer)
            ->post("/admin/kyc/{$member->id}/decision", [
                'decision' => 'APPROVED',
                'notes' => 'مدارک کامل و مطابق اصل است؛ جواز کسب معتبر تا پایان سال.',
            ])
            ->assertRedirect(route('admin.kyc.index'));

        $this->assertSame('APPROVED', DB::table('kyc_profiles')->where('organization_id', $member->id)->value('status'));

        $review = DB::table('kyc_reviews')->where('organization_id', $member->id)->first();
        $this->assertNotNull($review);
        $this->assertSame('APPROVED', $review->decision);
        $this->assertSame((int) $officer->id, (int) $review->reviewer_user_id);

        // The automated checks the officer saw are stored with the decision.
        $this->assertNotNull($review->automated_checks);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.kyc.approved',
            'organization_id' => $member->id,
        ]);
    }

    private function createProfile(int $organizationId, string $status, DateTimeInterface $submittedAt): int
    {
        return (int) DB::table('kyc_profiles')->insertGetId([
            'organization_id' => $organizationId,
            'status' => $status,
            'submitted_at' => $submittedAt,
            'submission_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDocument(int $organizationId): int
    {
        return (int) DB::table('documents')->insertGetId([
            'organization_id' => $organizationId,
            'type' => 'BUSINESS_LICENSE',
            'disk' => 'local',
            'storage_path' => 'kyc/'.$organizationId.'/license.pdf',
            'original_filename' => 'license.pdf',
            'file_hash' => str_repeat('a', 64),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
