<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests\Feature\Http;

use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Risk\Application\DailyCounters;
use App\Modules\Risk\Application\RiskProfileService;
use App\Modules\Risk\Domain\CollateralStatus;
use App\Modules\Risk\Domain\CollateralType;
use App\Modules\Risk\Domain\LimitType;
use App\Modules\Risk\Infrastructure\Models\Collateral;
use App\Modules\Risk\Infrastructure\Models\UserLimit;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\ApiTestCase;

#[Group('http')]
final class RiskEndpointsTest extends ApiTestCase
{
    #[Test]
    public function the_risk_profile_is_returned_in_the_documented_envelope(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/risk/profile');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.organization_id', (int) $organization->id);
        $response->assertJsonStructure([
            'data' => [
                'risk_level', 'credit_score', 'is_trading_allowed',
                'max_order_mg', 'max_daily_volume_mg', 'max_open_orders',
                'max_open_exposure_mg', 'allowed_settlement_types',
            ],
        ]);

        // Money and weight are integers on the wire, never strings or floats.
        self::assertIsInt($response->json('data.max_order_mg'));
        self::assertIsInt($response->json('data.max_open_exposure_rial'));
    }

    #[Test]
    public function display_companions_are_present_by_default_and_suppressible(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        $with = $this->actingAsUser($user)->getJson('/api/v1/risk/profile');
        $with->assertOk();
        self::assertIsString($with->json('data.max_order_display'));

        $without = $this->actingAsUser($user)
            ->getJson('/api/v1/risk/profile?include_display=false');
        $without->assertOk();
        self::assertNull($without->json('data.max_order_display'));
    }

    #[Test]
    public function limits_report_both_the_ceilings_and_todays_consumption(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        $profile = $this->app->make(RiskProfileService::class)->profile((int) $organization->id);

        $counters = $this->app->make(DailyCounters::class);
        $counters->reset((int) $organization->id, (int) $user->id);
        $counters->increment(
            (int) $organization->id,
            FineWeight::fromMilligrams(250_000),
            Rial::fromRial(78_510_000),
            (int) $user->id,
        );

        $response = $this->actingAsUser($user)->getJson('/api/v1/risk/limits');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.limits.max_daily_volume_mg', $profile->max_daily_volume_mg);
        $response->assertJsonPath('data.usage.daily_volume_mg', 250_000);
        $response->assertJsonPath('data.usage.daily_value_rial', 78_510_000);
        $response->assertJsonPath('data.usage.daily_trade_count', 1);
        $response->assertJsonPath(
            'data.usage.daily_volume_remaining_mg',
            $profile->max_daily_volume_mg - 250_000,
        );
        $response->assertJsonPath('data.user_limit.is_set', false);

        $counters->reset((int) $organization->id, (int) $user->id);
    }

    #[Test]
    public function limits_include_the_callers_own_user_ceiling_when_one_exists(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        UserLimit::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'max_order_mg' => 100_000,
            'max_daily_volume_mg' => 400_000,
            'requires_approval_above_mg' => 50_000,
            'is_active' => true,
        ]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/risk/limits');

        $response->assertOk();
        $response->assertJsonPath('data.user_limit.is_set', true);
        $response->assertJsonPath('data.user_limit.max_order_mg', 100_000);
        $response->assertJsonPath('data.user_limit.max_daily_volume_mg', 400_000);
        $response->assertJsonPath('data.user_limit.requires_approval_above_mg', 50_000);
    }

    #[Test]
    public function a_colleagues_user_limit_never_appears_in_my_limits(): void
    {
        $organization = $this->makeOrganization();
        $me = $this->makeUser($organization, [RoleEnum::TRADER]);
        $colleague = $this->makeUser($organization, [RoleEnum::TRADER]);

        UserLimit::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $colleague->id,
            'max_order_mg' => 1,
            'max_daily_volume_mg' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAsUser($me)->getJson('/api/v1/risk/limits');

        $response->assertOk();
        $response->assertJsonPath('data.user_limit.is_set', false);
        $response->assertJsonPath('data.user_id', (int) $me->id);
    }

    #[Test]
    public function exposure_reports_open_obligations_and_collateral_coverage(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/risk/exposure');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonStructure([
            'data' => [
                'exposure' => ['gold_mg', 'rial', 'open_order_count'],
                'limits' => ['max_open_exposure_mg', 'gold_utilisation_bps'],
                'coverage' => [
                    'collateral_value_rial', 'exposure_value_rial', 'ratio_bps',
                    'status', 'requires_margin_call', 'shortfall_to_healthy_rial',
                ],
            ],
        ]);

        self::assertIsInt($response->json('data.coverage.collateral_value_rial'));
    }

    #[Test]
    public function collaterals_list_only_the_callers_own_pledges(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        Collateral::query()->create([
            'organization_id' => $mine->id,
            'type' => CollateralType::RIAL_DEPOSIT->value,
            'status' => CollateralStatus::ACTIVE->value,
            'nominal_value_rial' => 1_000_000_000,
            'acceptance_factor_bps' => 10_000,
            'reference' => 'MINE',
        ]);

        Collateral::query()->create([
            'organization_id' => $theirs->id,
            'type' => CollateralType::BANK_GUARANTEE->value,
            'status' => CollateralStatus::ACTIVE->value,
            'nominal_value_rial' => 9_000_000_000,
            'acceptance_factor_bps' => 9_500,
            'reference' => 'THEIRS',
        ]);

        $response = $this->actingAsUser($user)->getJson('/api/v1/risk/collaterals');

        $response->assertOk();
        $this->assertEnvelope($response);
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.reference', 'MINE');
        $response->assertJsonPath('data.0.accepted_value_rial', 1_000_000_000);

        self::assertStringNotContainsString('THEIRS', (string) $response->getContent());
    }

    #[Test]
    public function every_risk_endpoint_rejects_an_anonymous_caller(): void
    {
        foreach (['/api/v1/risk/profile', '/api/v1/risk/limits', '/api/v1/risk/exposure', '/api/v1/risk/collaterals'] as $path) {
            $response = $this->getJson($path);

            $response->assertStatus(401);
            $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
        }
    }

    #[Test]
    public function a_limit_increase_request_is_owner_level_and_a_trader_is_refused(): void
    {
        $organization = $this->makeOrganization();
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($trader)->postJson('/api/v1/risk/limit-increase-request', [
            'limit_type' => LimitType::PER_ORDER->value,
            'requested_value' => 5_000_000,
        ]);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'FORBIDDEN_ROLE');
    }

    #[Test]
    public function an_owner_may_raise_a_limit_increase_request(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        $profile = $this->app->make(RiskProfileService::class)->profile((int) $organization->id);

        $response = $this->actingAsUser($owner)->postJson('/api/v1/risk/limit-increase-request', [
            'limit_type' => LimitType::PER_ORDER->value,
            'requested_value' => $profile->max_order_mg + 1_000_000,
            'justification' => 'حجم معاملات ما افزایش یافته است.',
        ]);

        $response->assertStatus(201);
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.limit_type', LimitType::PER_ORDER->value);
        $response->assertJsonPath('data.current_value', $profile->max_order_mg);
        $response->assertJsonPath('data.requested_value', $profile->max_order_mg + 1_000_000);

        // The automatic screen of §11.8 can only reject; a brand-new member
        // fails the tenure and history prerequisites and is told which.
        $response->assertJsonPath('data.status', 'AUTO_REJECTED');
        self::assertContains('MIN_90_DAYS_ACTIVE', $response->json('data.failed_prerequisites'));

        // The compliance officer's evidence trail stays internal.
        self::assertNull($response->json('data.prerequisite_snapshot'));
    }

    #[Test]
    public function a_limit_increase_request_validates_its_payload(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        $response = $this->actingAsUser($owner)->postJson('/api/v1/risk/limit-increase-request', [
            'limit_type' => 'NOT_A_LIMIT',
            'requested_value' => '250.5',
        ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['field_errors' => ['limit_type', 'requested_value']]]);
    }

    #[Test]
    public function a_limit_increase_that_does_not_raise_the_ceiling_is_a_field_error(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, [RoleEnum::OWNER]);

        $profile = $this->app->make(RiskProfileService::class)->profile((int) $organization->id);

        $response = $this->actingAsUser($owner)->postJson('/api/v1/risk/limit-increase-request', [
            'limit_type' => LimitType::PER_ORDER->value,
            'requested_value' => $profile->max_order_mg,
        ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['field_errors' => ['requested_value']]]);
    }
}
