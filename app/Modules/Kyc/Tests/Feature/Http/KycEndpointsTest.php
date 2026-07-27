<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Tests\Feature\Http;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Infrastructure\Models\Document;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\ApiTestCase;

/** `GET /organization/kyc` and `POST /organization/kyc/submit` — §2.2. */
#[Group('http')]
final class KycEndpointsTest extends ApiTestCase
{
    #[Test]
    public function the_kyc_status_comes_back_in_the_documented_envelope(): void
    {
        $organization = $this->makeOrganization(OrganizationStatus::PENDING);
        $user = $this->makeUser($organization, [RoleEnum::OWNER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/organization/kyc');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.organization_id', (int) $organization->id);
        $response->assertJsonPath('data.status', KycStatus::DRAFT->value);
        $response->assertJsonPath('data.is_complete', false);

        // The outstanding items are machine-readable so the client can tick
        // them off; an empty dossier is missing every required document.
        self::assertContains('bank_account:missing', $response->json('data.missing_items'));
        self::assertContains('business_license:missing', $response->json('data.missing_items'));
    }

    #[Test]
    public function an_anonymous_caller_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/organization/kyc');

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
    }

    #[Test]
    public function submitting_an_incomplete_dossier_lists_what_is_missing(): void
    {
        $organization = $this->makeOrganization(OrganizationStatus::PENDING);
        $user = $this->makeUser($organization, [RoleEnum::OWNER]);

        $response = $this->actingAsUser($user)->postJson('/api/v1/organization/kyc/submit');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'KYC_INCOMPLETE');

        self::assertIsArray($response->json('error.details.missing_items'));
        self::assertNotSame([], $response->json('error.details.missing_items'));
    }

    #[Test]
    public function a_complete_dossier_can_be_submitted(): void
    {
        $organization = $this->makeOrganization(OrganizationStatus::PENDING);
        $user = $this->makeUser($organization, [RoleEnum::OWNER]);

        $this->completeTheDossier($organization);

        $response = $this->actingAsUser($user)->postJson('/api/v1/organization/kyc/submit');

        $response->assertStatus(202);
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.status', KycStatus::SUBMITTED->value);
        $response->assertJsonPath('data.missing_items', []);
        $response->assertJsonPath('data.is_complete', true);
    }

    #[Test]
    public function a_role_without_kyc_edit_may_not_submit(): void
    {
        $organization = $this->makeOrganization(OrganizationStatus::PENDING);
        $viewer = $this->makeUser($organization, [RoleEnum::VIEWER]);

        $this->completeTheDossier($organization);

        $response = $this->actingAsUser($viewer)->postJson('/api/v1/organization/kyc/submit');

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'FORBIDDEN_ROLE');
    }

    /** Everything KycSubmissionService::missingItems() asks of an INDIVIDUAL. */
    private function completeTheDossier(Organization $organization): void
    {
        foreach (DocumentType::requiredFor($organization->type) as $type) {
            Document::query()->create([
                'organization_id' => $organization->id,
                'type' => $type->value,
                'disk' => 'local',
                'storage_path' => 'kyc/'.$organization->id.'/'.strtolower($type->value).'/'.uniqid().'.jpg',
                'file_hash' => hash('sha256', $type->value.uniqid()),
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
            ]);
        }

        BusinessLicense::query()->create([
            'organization_id' => $organization->id,
            'license_no' => 'LIC-'.$organization->id,
            'issuing_union' => 'اتحادیه طلا و جواهر تهران',
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'status' => 'VALID',
        ]);

        $account = new BankAccount;
        $account->organization_id = $organization->id;
        $account->bank_name = 'بانک ملی';
        $account->account_holder_name = (string) $organization->display_name;
        $account->setIban('IR980170000000108888888801');
        $account->save();
    }
}
