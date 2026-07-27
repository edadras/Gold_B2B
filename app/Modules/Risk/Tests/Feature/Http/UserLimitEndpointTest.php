<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests\Feature\Http;

use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Risk\Application\RiskProfileService;
use App\Modules\Risk\Infrastructure\Models\UserLimit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\ApiTestCase;

/** `PUT /organization/users/{id}/limits` — §2.2, owned by Risk. */
#[Group('http')]
final class UserLimitEndpointTest extends ApiTestCase
{
    #[Test]
    public function an_owner_sets_a_colleagues_trading_ceiling(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/'.$trader->id.'/limits', [
                'max_order_mg' => 250_000,
                'max_daily_volume_mg' => 1_000_000,
                'requires_approval_above_mg' => 150_000,
                'is_active' => true,
            ]);

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.user_id', (int) $trader->id);
        $response->assertJsonPath('data.organization_id', (int) $organization->id);
        $response->assertJsonPath('data.max_order_mg', 250_000);
        $response->assertJsonPath('data.max_daily_volume_mg', 1_000_000);
        $response->assertJsonPath('data.requires_approval_above_mg', 150_000);
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('user_limits', [
            'user_id' => $trader->id,
            'organization_id' => $organization->id,
            'max_order_mg' => 250_000,
        ]);
    }

    #[Test]
    public function a_second_write_updates_the_same_row_and_keeps_unsent_fields(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/'.$trader->id.'/limits', [
                'max_order_mg' => 250_000,
                'max_daily_volume_mg' => 1_000_000,
            ])->assertOk();

        $second = $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/'.$trader->id.'/limits', [
                'is_active' => false,
            ]);

        $second->assertOk();
        $second->assertJsonPath('data.is_active', false);
        $second->assertJsonPath('data.max_order_mg', 250_000);

        self::assertSame(1, UserLimit::query()->where('user_id', $trader->id)->count());
    }

    #[Test]
    public function a_null_weight_falls_back_to_the_member_ceiling(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $profile = $this->app->make(RiskProfileService::class)->profile((int) $organization->id);

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/'.$trader->id.'/limits', [
                'max_order_mg' => null,
                'max_daily_volume_mg' => null,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.max_order_mg', $profile->max_order_mg);
        $response->assertJsonPath('data.max_daily_volume_mg', $profile->max_daily_volume_mg);
    }

    #[Test]
    public function a_trader_may_not_set_limits(): void
    {
        $organization = $this->makeOrganization();
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);
        $colleague = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($trader)
            ->putJson('/api/v1/organization/users/'.$colleague->id.'/limits', [
                'max_order_mg' => 999_000_000,
            ]);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'FORBIDDEN_ROLE');
    }

    #[Test]
    public function another_organizations_user_is_404_not_403(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();

        $owner = $this->makeUser($mine, [RoleEnum::OWNER]);
        $stranger = $this->makeUser($theirs, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/'.$stranger->id.'/limits', [
                'max_order_mg' => 1_000,
            ]);

        // 404, never 403: a 403 would confirm the user id exists somewhere on
        // the platform and turn the endpoint into a membership oracle.
        $response->assertStatus(404);
        $this->assertErrorEnvelope($response, 'RESOURCE_NOT_FOUND');

        $this->assertDatabaseMissing('user_limits', ['user_id' => $stranger->id]);
    }

    #[Test]
    public function a_user_id_that_does_not_exist_is_also_404(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/987654/limits', ['max_order_mg' => 1_000])
            ->assertStatus(404);
    }

    #[Test]
    public function the_payload_is_validated_as_integers(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/organization/users/'.$trader->id.'/limits', [
                'max_order_mg' => '250.5',
                'max_daily_volume_mg' => -1,
                'is_active' => 'perhaps',
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure([
            'error' => ['field_errors' => ['max_order_mg', 'max_daily_volume_mg', 'is_active']],
        ]);
    }

    #[Test]
    public function an_anonymous_caller_is_rejected(): void
    {
        $response = $this->putJson('/api/v1/organization/users/1/limits', ['max_order_mg' => 1]);

        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
    }
}
