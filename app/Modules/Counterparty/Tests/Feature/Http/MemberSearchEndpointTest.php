<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests\Feature\Http;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\Role as RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\ApiTestCase;

/**
 * `GET /members/search` — §2.11.
 *
 * The privacy assertions here are the point of the file: this is the widest
 * read surface in the platform (every role in every member can call it, and it
 * returns rows belonging to strangers), so the payload is asserted key by key
 * rather than "contains what we expect".
 */
#[Group('http')]
final class MemberSearchEndpointTest extends ApiTestCase
{
    #[Test]
    public function a_member_finds_other_members_by_display_name(): void
    {
        $mine = $this->makeOrganization(overrides: ['display_name' => 'طلافروشی الماس']);
        $other = $this->makeOrganization(overrides: ['display_name' => 'طلای پارسیان', 'city' => 'اصفهان']);
        $this->makeOrganization(overrides: ['display_name' => 'صرافی نور']);

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=پارسیان');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.organization_id', (int) $other->id);
        $response->assertJsonPath('data.0.display_name', 'طلای پارسیان');
        $response->assertJsonPath('data.0.city', 'اصفهان');
    }

    #[Test]
    public function the_payload_is_exactly_the_five_permitted_fields(): void
    {
        $mine = $this->makeOrganization(overrides: ['display_name' => 'طلافروشی الماس']);
        $this->makeOrganization(overrides: ['display_name' => 'طلای پارسیان']);

        $user = $this->makeUser($mine, [RoleEnum::VIEWER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=پارسیان');

        $response->assertOk();

        self::assertSame(
            ['organization_id', 'display_name', 'city', 'type', 'status'],
            array_keys($response->json('data.0')),
        );
    }

    #[Test]
    public function no_identifier_contact_detail_or_balance_ever_appears(): void
    {
        $mine = $this->makeOrganization(overrides: ['display_name' => 'طلافروشی الماس']);
        $target = $this->makeOrganization(overrides: [
            'display_name' => 'طلای پارسیان',
            'email' => 'parsian@example.test',
            'phone' => '02112345678',
            'registration_no' => 'REG-9911',
        ]);

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=پارسیان');
        $response->assertOk();

        $body = (string) $response->getContent();

        // The values themselves, taken from the row that was returned.
        foreach ([
            (string) $target->mobile,
            'parsian@example.test',
            '02112345678',
            'REG-9911',
            (string) $target->national_id_hash,
        ] as $secret) {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $body);
        }

        // And the field names, so a future rename cannot smuggle one back in.
        foreach ([
            'national_id', 'legal_id', 'mobile', 'email', 'phone', 'balance',
            'gold_balance', 'rial_balance', 'credit', 'risk_level', 'counterparty',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
    }

    #[Test]
    public function the_caller_never_finds_itself(): void
    {
        $mine = $this->makeOrganization(overrides: ['display_name' => 'طلای پارسیان']);
        $other = $this->makeOrganization(overrides: ['display_name' => 'طلای پارسیان دوم']);

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=پارسیان');

        $response->assertOk();
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.organization_id', (int) $other->id);
    }

    #[Test]
    public function platform_organizations_are_never_listed(): void
    {
        $mine = $this->makeOrganization(overrides: ['display_name' => 'طلافروشی الماس']);
        $this->makeOrganization(OrganizationStatus::ACTIVE, [
            'display_name' => 'اپراتور الماس',
            'is_platform' => true,
        ]);

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=الماس');

        $response->assertOk();
        $response->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function a_one_character_query_is_refused(): void
    {
        $mine = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=ا');

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['field_errors' => ['q']]]);
    }

    #[Test]
    public function a_missing_query_is_refused(): void
    {
        $mine = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search');

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['field_errors' => ['q']]]);
    }

    #[Test]
    public function a_wildcard_cannot_be_used_to_page_through_the_membership(): void
    {
        $mine = $this->makeOrganization(overrides: ['display_name' => 'طلافروشی الماس']);
        $this->makeOrganization(overrides: ['display_name' => 'طلای پارسیان']);
        $this->makeOrganization(overrides: ['display_name' => 'صرافی نور']);

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/search?q=%25%25');

        $response->assertOk();
        $response->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function an_anonymous_caller_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/members/search?q=طلا');

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
    }
}
