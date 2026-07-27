<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Tests\Feature\Http;

use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Reputation\Application\StatsUpdater;
use App\Modules\Reputation\Contracts\PublicProfile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\Api\ApiTestCase;

/**
 * `GET /members/{id}/reputation` (§2.11) and `GET /reputation/me`.
 *
 * The privacy assertions are the reason this file exists: the public endpoint
 * is readable for ANY member id, so its payload must be exactly the whitelist
 * of docs/03-domain/14-reputation.md §14.9 and nothing else.
 */
#[Group('http')]
final class ReputationEndpointsTest extends ApiTestCase
{
    #[Test]
    public function a_public_profile_is_readable_for_another_member(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->seedReputation((int) $theirs->id);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/'.$theirs->id.'/reputation');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.organization_id', (int) $theirs->id);
        $response->assertJsonPath('data.total_trades', 1);
        $response->assertJsonPath('data.total_volume_mg', 250_000);
        $response->assertJsonPath('data.on_time_settlement_rate_bps', 10_000);

        self::assertIsInt($response->json('data.total_volume_mg'));
    }

    #[Test]
    public function the_public_payload_is_exactly_the_permitted_field_set(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::VIEWER]);

        $this->seedReputation((int) $theirs->id);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/'.$theirs->id.'/reputation');

        $response->assertOk();

        // Served verbatim from PublicProfile::toArray(); no `_display`
        // companion is added, so the HTTP payload cannot drift away from the
        // whitelist PublicProfilePrivacyTest guards.
        self::assertSame([
            'organization_id',
            'verification_tier',
            'tier_label',
            'tier_badge',
            'total_trades',
            'total_volume_mg',
            'on_time_settlement_rate_bps',
            'dispute_rate_bps',
            'avg_settlement_minutes',
            'distinct_counterparties',
            'rfq_response_rate_bps',
            'rfq_avg_response_minutes',
            'quote_fill_rate_bps',
            'member_since',
            'last_active_at',
            'is_active',
            'is_new_member',
        ], array_keys($response->json('data')));
    }

    #[Test]
    public function no_identifier_contact_detail_balance_or_counterparty_identity_appears(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization(overrides: [
            'display_name' => 'طلای پارسیان',
            'email' => 'parsian@example.test',
            'registration_no' => 'REG-9911',
        ]);
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->seedReputation((int) $theirs->id);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/'.$theirs->id.'/reputation');
        $response->assertOk();

        $body = (string) json_encode($response->json('data'), JSON_UNESCAPED_UNICODE);

        foreach ([
            (string) $theirs->mobile,
            (string) $theirs->national_id_hash,
            'parsian@example.test',
            'REG-9911',
        ] as $secret) {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $body);
        }

        // §14.9's forbidden list, by field name — a column added upstream must
        // break this test rather than slip out silently.
        foreach ([
            'national_id', 'legal_id', 'mobile', 'email', 'phone',
            'balance', 'gold_balance', 'rial', 'price',
            'counterparty_org', 'counterparties_list',
            'aml', 'suspend', 'kyc', 'credit', 'score', 'risk_level',
            'internal_note', 'limit',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
    }

    #[Test]
    public function an_unknown_member_id_answers_a_zeroed_public_profile(): void
    {
        $mine = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        // Deliberately not 404: the profile is public, and answering 404 for
        // "no statistics yet" versus 200 for "some statistics" would leak which
        // members have traded.
        $response = $this->actingAsUser($user)->getJson('/api/v1/members/987654/reputation');

        $response->assertOk();
        $response->assertJsonPath('data.organization_id', 987654);
        $response->assertJsonPath('data.total_trades', 0);
        $response->assertJsonPath('data.is_new_member', true);
        $response->assertJsonPath('data.verification_tier', 'BRONZE');
    }

    #[Test]
    public function the_public_profile_of_my_own_member_is_the_same_narrow_payload(): void
    {
        $mine = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->seedReputation((int) $mine->id);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/'.$mine->id.'/reputation');

        $response->assertOk();
        self::assertCount(17, $response->json('data'));
        self::assertNull($response->json('data.settlements_total'));
    }

    #[Test]
    public function my_own_statistics_are_richer_than_the_public_profile(): void
    {
        $mine = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::ACCOUNTANT]);

        $this->seedReputation((int) $mine->id);

        $response = $this->actingAsUser($user)->getJson('/api/v1/reputation/me');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.organization_id', (int) $mine->id);

        // §14.9: the member sees the numerators and denominators so it can
        // check the arithmetic behind every rate.
        $response->assertJsonStructure([
            'data' => [
                'settlements_total', 'settlements_on_time', 'settlements_late',
                'settlements_defaulted', 'disputes_involved', 'disputes_lost',
                'rfq_received', 'rfq_responded', 'quotes_accepted', 'quotes_filled',
                'maker_volume_mg', 'taker_volume_mg', 'maker_share_bps',
                'min_countable_trade_mg',
            ],
        ]);

        $response->assertJsonPath('data.settlements_total', 1);
        $response->assertJsonPath('data.settlements_on_time', 1);
    }

    #[Test]
    public function my_own_statistics_carry_suppressible_display_companions(): void
    {
        $mine = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::ACCOUNTANT]);

        $this->seedReputation((int) $mine->id);

        $with = $this->actingAsUser($user)->getJson('/api/v1/reputation/me');
        $with->assertOk();
        self::assertIsString($with->json('data.total_volume_display'));

        $without = $this->actingAsUser($user)->getJson('/api/v1/reputation/me?include_display=false');
        $without->assertOk();
        self::assertNull($without->json('data.total_volume_display'));
    }

    #[Test]
    public function reputation_me_never_returns_another_members_statistics(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();

        $me = $this->makeUser($mine, [RoleEnum::ACCOUNTANT]);

        /** @var StatsUpdater $stats */
        $stats = $this->app->make(StatsUpdater::class);

        $stats->ensure((int) $mine->id, Carbon::now()->subDays(400));
        $stats->recordSettlement((int) $mine->id, 100_000, onTime: true, settlementMinutes: 5);

        // The counterparty has a much bigger book; none of it may show up.
        $stats->ensure((int) $theirs->id, Carbon::now()->subDays(400));
        $stats->recordSettlement((int) $theirs->id, 900_000, onTime: false, settlementMinutes: 900);
        $stats->recordSettlement((int) $theirs->id, 900_000, onTime: false, settlementMinutes: 900);

        $response = $this->actingAsUser($me)->getJson('/api/v1/reputation/me');

        $response->assertOk();
        // The organisation id comes from the token, never from the client, so
        // there is no parameter to point at somebody else's row.
        $response->assertJsonPath('data.organization_id', (int) $mine->id);
        $response->assertJsonPath('data.settlements_total', 1);
        $response->assertJsonPath('data.total_volume_mg', 100_000);
    }

    #[Test]
    public function both_endpoints_reject_an_anonymous_caller(): void
    {
        foreach (['/api/v1/members/1/reputation', '/api/v1/reputation/me'] as $path) {
            $response = $this->getJson($path);

            $response->assertStatus(401);
            $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
        }
    }

    #[Test]
    public function a_caller_whose_role_grants_nothing_is_refused(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();

        // A user with no organisation role at all holds no permission, so both
        // legs fail on the role side: 403 FORBIDDEN_ROLE, not a silent 200.
        $roleless = $this->makeUser($mine, []);

        $public = $this->actingAsUser($roleless)->getJson('/api/v1/members/'.$theirs->id.'/reputation');
        $public->assertStatus(403);
        $this->assertErrorEnvelope($public, 'FORBIDDEN_ROLE');

        $own = $this->actingAsUser($roleless)->getJson('/api/v1/reputation/me');
        $own->assertStatus(403);
        $this->assertErrorEnvelope($own, 'FORBIDDEN_ROLE');
    }

    #[Test]
    public function the_public_profile_shape_matches_the_contract_object(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->seedReputation((int) $theirs->id);

        $response = $this->actingAsUser($user)->getJson('/api/v1/members/'.$theirs->id.'/reputation');
        $response->assertOk();

        $reflection = new ReflectionClass(PublicProfile::class);
        $propertyCount = count($reflection->getProperties(ReflectionProperty::IS_PUBLIC));

        self::assertSame($propertyCount, count($response->json('data')));
    }

    private function seedReputation(int $organizationId): void
    {
        /** @var StatsUpdater $stats */
        $stats = $this->app->make(StatsUpdater::class);

        $stats->ensure($organizationId, Carbon::now()->subDays(400));
        $stats->recordSettlement($organizationId, 250_000, onTime: true, settlementMinutes: 23);
    }
}
