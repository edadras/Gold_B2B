<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Tests\Feature;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Events\OrganizationActivated;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Application\IncompleteKycException;
use App\Modules\Kyc\Application\KycReviewService;
use App\Modules\Kyc\Application\KycSubmissionService;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Domain\KycDecision;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Events\KycApproved;
use App\Modules\Kyc\Events\KycInfoRequired;
use App\Modules\Kyc\Events\KycRejected;
use App\Modules\Kyc\Events\KycSubmitted;
use App\Modules\Kyc\Infrastructure\Models\KycProfile;
use App\Modules\Kyc\Infrastructure\Models\KycReview;
use App\Modules\Kyc\Tests\KycTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

final class KycFlowTest extends KycTestCase
{
    use RefreshDatabase;

    private KycSubmissionService $submissions;

    private KycReviewService $reviews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->submissions = $this->app->make(KycSubmissionService::class);
        $this->reviews = $this->app->make(KycReviewService::class);
    }

    public function test_full_flow_submit_info_required_resubmit_approve_activates_the_member(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $this->completeDossier($organization);
        $officer = $this->makeComplianceOfficer();

        // Kyc's services are addressed by id; the factories stay because test
        // fixtures are exempt from the module-boundary rule.
        $organizationId = (int) $organization->id;
        $officerId = (int) $officer->id;

        // --- submit ----------------------------------------------------
        $profile = $this->submissions->submit($organizationId);

        $this->assertSame(KycStatus::SUBMITTED, $profile->status);
        $this->assertSame(1, (int) $profile->submission_count);
        $this->assertSame(OrganizationStatus::UNDER_REVIEW, $organization->fresh()->status);
        Event::assertDispatched(KycSubmitted::class);

        // --- officer asks for more -------------------------------------
        $profile = $this->reviews->requestInfo(
            $organizationId,
            $officerId,
            'تصویر جواز کسب خوانا نیست.',
            ['document:BUSINESS_LICENSE'],
        );

        $this->assertSame(KycStatus::INFO_REQUIRED, $profile->status);
        $this->assertSame(OrganizationStatus::INFO_REQUIRED, $organization->fresh()->status);
        Event::assertDispatched(
            KycInfoRequired::class,
            fn (KycInfoRequired $e): bool => $e->missingItems === ['document:BUSINESS_LICENSE'],
        );

        // --- member fixes it and resubmits -----------------------------
        $this->attachDocument($organization->fresh(), DocumentType::BUSINESS_LICENSE);

        $profile = $this->submissions->submit($organizationId);

        $this->assertSame(KycStatus::SUBMITTED, $profile->status);
        $this->assertSame(2, (int) $profile->submission_count);
        $this->assertSame(OrganizationStatus::UNDER_REVIEW, $organization->fresh()->status);

        // --- officer approves ------------------------------------------
        $profile = $this->reviews->approve($organizationId, $officerId, 'مدارک کامل و معتبر است.');

        $this->assertSame(KycStatus::APPROVED, $profile->status);
        $this->assertNotNull($profile->next_review_due_at);

        // VERIFIED → ACTIVE is what makes the ledger open the member's accounts.
        $this->assertSame(OrganizationStatus::ACTIVE, $organization->fresh()->status);
        $this->assertNotNull($organization->fresh()->activated_at);

        Event::assertDispatched(KycApproved::class);
        Event::assertDispatched(
            OrganizationActivated::class,
            fn (OrganizationActivated $e): bool => $e->organizationId === (int) $organization->id,
        );

        // Both the VERIFIED and the ACTIVE hop are on the record.
        $this->assertDatabaseHas('organization_status_events', [
            'organization_id' => $organization->id,
            'from_status' => OrganizationStatus::UNDER_REVIEW->value,
            'to_status' => OrganizationStatus::VERIFIED->value,
        ]);
        $this->assertDatabaseHas('organization_status_events', [
            'organization_id' => $organization->id,
            'from_status' => OrganizationStatus::VERIFIED->value,
            'to_status' => OrganizationStatus::ACTIVE->value,
            'actor_type' => 'SYSTEM',
        ]);

        // Three decisions were taken in total? No — two: info-required, approve.
        $this->assertSame(2, KycReview::query()->where('organization_id', $organization->id)->count());
    }

    public function test_an_incomplete_dossier_cannot_be_submitted(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();

        try {
            $this->submissions->submit((int) $organization->id);
            $this->fail('an empty dossier must not reach the compliance queue');
        } catch (IncompleteKycException $e) {
            $this->assertContains('document:NATIONAL_CARD_FRONT', $e->missingItems);
            $this->assertContains('business_license:missing', $e->missingItems);
            $this->assertContains('bank_account:missing', $e->missingItems);
        }

        $this->assertSame(
            KycStatus::DRAFT,
            KycProfile::query()->where('organization_id', $organization->id)->firstOrFail()->status,
        );
        $this->assertSame(OrganizationStatus::PENDING, $organization->fresh()->status);
        Event::assertNotDispatched(KycSubmitted::class);
    }

    public function test_a_legal_entity_needs_its_extra_documents_and_a_signatory(): void
    {
        Event::fake();

        $organization = Organization::factory()->legalEntity()->create();

        $missing = $this->submissions->missingItems((int) $organization->id);

        $this->assertContains('document:ARTICLES_OF_ASSOCIATION', $missing);
        $this->assertContains('document:SIGNATURE_CERTIFICATE', $missing);
        $this->assertContains('signatory:missing', $missing);
        $this->assertContains('identity:legal_id', $missing);

        $this->completeDossier($organization);

        $this->assertTrue($this->submissions->isComplete((int) $organization->id));
    }

    public function test_a_compliance_officer_cannot_review_their_own_organisation(): void
    {
        Event::fake();

        // The officer's own employer somehow ends up in the review queue.
        $ownOrganization = Organization::factory()->platform()->create();
        $officer = $this->makeUser($ownOrganization, [RoleEnum::COMPLIANCE_OFFICER]);

        $this->completeDossier($ownOrganization);
        $ownOrganization->forceFill(['status' => OrganizationStatus::UNDER_REVIEW])->save();
        $this->submissions->profileFor((int) $ownOrganization->id)
            ->forceFill(['status' => KycStatus::SUBMITTED])->save();

        foreach (['approve', 'reject', 'requestInfo', 'claim'] as $method) {
            try {
                $method === 'claim'
                    ? $this->reviews->claim((int) $ownOrganization->id, (int) $officer->id)
                    : $this->reviews->{$method}((int) $ownOrganization->id, (int) $officer->id, 'یادداشت معتبر');

                $this->fail("{$method} on the officer's own organisation must be refused");
            } catch (OperationNotPermittedException $e) {
                $this->assertSame('officer_cannot_review_own_organization', $e->reason);
            }
        }

        $this->assertSame(0, KycReview::query()->count());
    }

    public function test_a_user_without_the_review_permission_cannot_decide(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $this->completeDossier($organization);
        $this->submissions->submit((int) $organization->id);

        $notAnOfficer = $this->makeUser(Organization::factory()->platform()->create(), [RoleEnum::SUPPORT_AGENT]);

        try {
            $this->reviews->approve((int) $organization->id, (int) $notAnOfficer->id, 'تأیید');
            $this->fail('a support agent must not be able to approve KYC');
        } catch (OperationNotPermittedException $e) {
            $this->assertSame('missing_permission:platform.kyc.review', $e->reason);
        }
    }

    public function test_every_decision_requires_a_written_note(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $this->completeDossier($organization);
        $this->submissions->submit((int) $organization->id);

        $officer = $this->makeComplianceOfficer();

        foreach (['approve', 'reject', 'requestInfo'] as $method) {
            try {
                $this->reviews->{$method}((int) $organization->id, (int) $officer->id, '   ');
                $this->fail("{$method} with a blank note must be refused");
            } catch (OperationNotPermittedException $e) {
                $this->assertSame('kyc_decision_requires_a_written_note', $e->reason);
            }
        }

        $this->assertSame(KycStatus::SUBMITTED, $this->submissions->profileFor((int) $organization->id)->status);
    }

    public function test_rejection_is_final_for_both_dossier_and_member(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $this->completeDossier($organization);
        $this->submissions->submit((int) $organization->id);

        $officer = $this->makeComplianceOfficer();

        $profile = $this->reviews->reject((int) $organization->id, (int) $officer->id, 'مدارک جعلی است.');

        $this->assertSame(KycStatus::REJECTED, $profile->status);
        $this->assertTrue($profile->status->isFinal());
        $this->assertSame(OrganizationStatus::REJECTED, $organization->fresh()->status);
        $this->assertTrue($organization->fresh()->status->isFinal());

        Event::assertDispatched(KycRejected::class);

        $this->assertDatabaseHas('kyc_reviews', [
            'organization_id' => $organization->id,
            'decision' => KycDecision::REJECTED->value,
            'notes' => 'مدارک جعلی است.',
        ]);
    }

    public function test_the_decision_stores_the_automated_checks_the_officer_saw(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $this->completeDossier($organization);
        $this->submissions->submit((int) $organization->id);

        $officer = $this->makeComplianceOfficer();
        $this->reviews->approve((int) $organization->id, (int) $officer->id, 'تأیید شد');

        /** @var KycReview $review */
        $review = KycReview::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();

        $checks = $review->automated_checks;

        $this->assertTrue($checks['national_id_valid']);
        $this->assertTrue($checks['iban_valid']);
        $this->assertTrue($checks['license_present']);
        $this->assertFalse($checks['duplicate_national_id']);
        $this->assertGreaterThan(0, $checks['license_days_remaining']);
    }

    public function test_next_review_date_follows_the_risk_band(): void
    {
        Event::fake();

        $organization = Organization::factory()->create(['risk_level' => 'HIGH']);
        $this->completeDossier($organization);
        $this->submissions->submit((int) $organization->id);

        $officer = $this->makeComplianceOfficer();
        $profile = $this->reviews->approve((int) $organization->id, (int) $officer->id, 'تأیید');

        // HIGH risk is re-reviewed every 12 months (docs §1.6).
        $this->assertSame(
            now()->addMonths(12)->toDateString(),
            $profile->next_review_due_at->toDateString(),
        );
    }
}
