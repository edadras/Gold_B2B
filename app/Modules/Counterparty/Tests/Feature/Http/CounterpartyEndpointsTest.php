<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests\Feature\Http;

use App\Modules\Counterparty\Application\RelationService;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Organization;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\ApiTestCase;

/** `/counterparties/*` — docs/05-api/02-endpoints.md §2.11. */
#[Group('http')]
final class CounterpartyEndpointsTest extends ApiTestCase
{
    #[Test]
    public function the_counterparty_list_is_scoped_to_the_caller(): void
    {
        [$mine, $theirs, $outsider] = [
            $this->makeOrganization(),
            $this->makeOrganization(),
            $this->makeOrganization(),
        ];

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->relations()->applyTrade((int) $mine->id, (int) $theirs->id, 250_000, -78_510_000, 'TRD-1');
        // A relation between two other members must not appear in my list.
        $this->relations()->applyTrade((int) $theirs->id, (int) $outsider->id, 100_000, -10_000_000, 'TRD-2');

        $response = $this->actingAsUser($user)->getJson('/api/v1/counterparties');

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.organization_id', (int) $mine->id);
        $response->assertJsonPath('data.0.counterparty_org_id', (int) $theirs->id);
        $response->assertJsonPath('data.0.gold_balance_mg', 250_000);
        $response->assertJsonPath('data.0.is_gold_receivable', true);

        self::assertIsInt($response->json('data.0.rial_balance'));
    }

    #[Test]
    public function a_relation_detail_is_returned_with_display_companions(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        $this->relations()->applyTrade((int) $mine->id, (int) $theirs->id, 250_000, -78_510_000, 'TRD-1');

        $response = $this->actingAsUser($user)->getJson('/api/v1/counterparties/'.$theirs->id);

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.counterparty_org_id', (int) $theirs->id);
        $response->assertJsonPath('data.total_trade_count', 1);
        self::assertIsString($response->json('data.gold_balance_display'));

        $plain = $this->actingAsUser($user)
            ->getJson('/api/v1/counterparties/'.$theirs->id.'?include_display=false');
        $plain->assertOk();
        self::assertNull($plain->json('data.gold_balance_display'));
    }

    #[Test]
    public function a_counterparty_we_have_no_relation_with_is_404_not_403(): void
    {
        $mine = $this->makeOrganization();
        $stranger = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        // A 403 would confirm the organisation exists and let a member walk the
        // whole membership; 404 for "unknown" and "not yours" alike.
        $response = $this->actingAsUser($user)->getJson('/api/v1/counterparties/'.$stranger->id);

        $response->assertStatus(404);
        $this->assertErrorEnvelope($response, 'RESOURCE_NOT_FOUND');
    }

    #[Test]
    public function another_members_relation_is_never_readable(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $outsider = $this->makeOrganization();

        $user = $this->makeUser($mine, [RoleEnum::TRADER]);

        // theirs <-> outsider have a rich relation; I have none with either.
        $this->relations()->applyTrade((int) $theirs->id, (int) $outsider->id, 900_000, -1, 'TRD-X');

        $this->actingAsUser($user)
            ->getJson('/api/v1/counterparties/'.$outsider->id)
            ->assertStatus(404);
    }

    #[Test]
    public function the_statement_reconciles_against_the_stored_balance(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::ACCOUNTANT]);

        $this->relations()->applyTrade(
            (int) $mine->id,
            (int) $theirs->id,
            250_000,
            -78_510_000,
            'TRD-1',
            occurredAt: Carbon::now()->subDays(2),
        );

        $query = http_build_query([
            'from' => Carbon::now()->subDays(7)->toIso8601String(),
            'to' => Carbon::now()->toIso8601String(),
        ]);

        $response = $this->actingAsUser($user)->getJson(
            '/api/v1/counterparties/'.$theirs->id.'/statement?'.$query
        );

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.counterparty_org_id', (int) $theirs->id);
        $response->assertJsonPath('data.movement_count', 1);
        $response->assertJsonPath('data.closing.gold_mg', 250_000);
        $response->assertJsonPath('data.stored.gold_mg', 250_000);
        $response->assertJsonPath('data.is_reconciled', true);
        $response->assertJsonPath('data.lines.0.running_gold_mg', 250_000);
    }

    #[Test]
    public function a_statement_for_an_unknown_counterparty_is_404(): void
    {
        $mine = $this->makeOrganization();
        $stranger = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::ACCOUNTANT]);

        $this->actingAsUser($user)
            ->getJson('/api/v1/counterparties/'.$stranger->id.'/statement')
            ->assertStatus(404);
    }

    #[Test]
    public function a_statement_window_is_validated(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $user = $this->makeUser($mine, [RoleEnum::ACCOUNTANT]);

        $response = $this->actingAsUser($user)->getJson(
            '/api/v1/counterparties/'.$theirs->id.'/statement?from=not-a-date&to=also-not'
        );

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['field_errors' => ['from', 'to']]]);
    }

    #[Test]
    public function an_owner_sets_a_counterparty_credit_limit_and_sees_the_headroom(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $owner = $this->makeUser($mine, [RoleEnum::OWNER]);

        $this->relations()->applyTrade((int) $mine->id, (int) $theirs->id, 250_000, 0, 'TRD-1');

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/counterparties/'.$theirs->id.'/limits', [
                'gold_limit_mg' => 1_000_000,
                'rial_limit' => 5_000_000_000,
            ]);

        $response->assertOk();
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.gold_limit_mg', 1_000_000);
        // Only the receivable side consumes the limit (§10.5).
        $response->assertJsonPath('data.gold_used_mg', 250_000);
        $response->assertJsonPath('data.gold_remaining_mg', 750_000);
        $response->assertJsonPath('data.rial_limit', 5_000_000_000);
    }

    #[Test]
    public function a_trader_may_not_set_a_counterparty_credit_limit(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $trader = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($trader)
            ->putJson('/api/v1/counterparties/'.$theirs->id.'/limits', ['gold_limit_mg' => 1_000_000]);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'FORBIDDEN_ROLE');
    }

    #[Test]
    public function a_credit_limit_payload_is_validated_as_integers(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $owner = $this->makeUser($mine, [RoleEnum::OWNER]);

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/counterparties/'.$theirs->id.'/limits', [
                'gold_limit_mg' => '250.5',
                'rial_limit' => -1,
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['field_errors' => ['gold_limit_mg', 'rial_limit']]]);
    }

    #[Test]
    public function flags_are_one_sided_and_never_mirrored_onto_the_counterparty(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $me = $this->makeUser($mine, [RoleEnum::OWNER]);
        $them = $this->makeUser($theirs, [RoleEnum::OWNER]);

        $this->actingAsUser($me)
            ->putJson('/api/v1/counterparties/'.$theirs->id.'/settings', [
                'is_blocked' => true,
                'internal_note' => 'دیر تسویه می‌کند',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_blocked', true)
            // Blocking withdraws auto-accept implicitly.
            ->assertJsonPath('data.auto_accept_otc', false);

        // §10.7: the blocked side is never told. Their view of me is untouched,
        // and the note never leaves my own book.
        $mirror = $this->actingAsUser($them)->getJson('/api/v1/counterparties/'.$mine->id);
        $mirror->assertOk();
        $mirror->assertJsonPath('data.is_blocked', false);
        self::assertStringNotContainsString('دیر تسویه می‌کند', (string) $mirror->getContent());
    }

    #[Test]
    public function the_private_internal_note_is_not_exposed_even_to_its_author(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $owner = $this->makeUser($mine, [RoleEnum::OWNER]);

        $response = $this->actingAsUser($owner)
            ->putJson('/api/v1/counterparties/'.$theirs->id.'/settings', [
                'is_trusted' => true,
                'internal_note' => 'یادداشت داخلی',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_trusted', true);

        // RelationSnapshot has no field for it by design, so no consumer of the
        // snapshot — this endpoint included — can leak it.
        self::assertNull($response->json('data.internal_note'));
    }

    #[Test]
    public function a_viewer_may_not_change_counterparty_settings(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $viewer = $this->makeUser($mine, [RoleEnum::VIEWER]);

        $response = $this->actingAsUser($viewer)
            ->putJson('/api/v1/counterparties/'.$theirs->id.'/settings', ['is_blocked' => true]);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'FORBIDDEN_ROLE');
    }

    #[Test]
    public function a_balance_confirmation_is_requested_with_the_figures_of_the_stated_date(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $treasurer = $this->makeUser($mine, [RoleEnum::TREASURER]);

        $this->relations()->applyTrade(
            (int) $mine->id,
            (int) $theirs->id,
            250_000,
            -78_510_000,
            'TRD-1',
            occurredAt: Carbon::now()->subDays(3),
        );

        $response = $this->actingAsUser($treasurer)
            ->postJson('/api/v1/counterparties/'.$theirs->id.'/confirm-balance', [
                'as_of' => Carbon::now()->subDay()->toIso8601String(),
                'note' => 'لطفاً مانده پایان ماه را تأیید کنید.',
            ]);

        $response->assertStatus(201);
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.organization_id', (int) $mine->id);
        $response->assertJsonPath('data.counterparty_org_id', (int) $theirs->id);
        $response->assertJsonPath('data.status', 'PENDING');
        $response->assertJsonPath('data.requester_gold_mg', 250_000);
        $response->assertJsonPath('data.requester_rial', -78_510_000);
        self::assertNull($response->json('data.responder_gold_mg'));
    }

    #[Test]
    public function a_trader_may_not_demand_a_balance_confirmation(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $trader = $this->makeUser($mine, [RoleEnum::TRADER]);

        $response = $this->actingAsUser($trader)
            ->postJson('/api/v1/counterparties/'.$theirs->id.'/confirm-balance', []);

        $response->assertStatus(403);
        $this->assertErrorEnvelope($response, 'FORBIDDEN_ROLE');
    }

    #[Test]
    public function a_future_as_of_date_is_a_field_error(): void
    {
        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();
        $treasurer = $this->makeUser($mine, [RoleEnum::TREASURER]);

        $response = $this->actingAsUser($treasurer)
            ->postJson('/api/v1/counterparties/'.$theirs->id.'/confirm-balance', [
                'as_of' => Carbon::now()->addYear()->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $this->assertErrorEnvelope($response, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['field_errors' => ['as_of']]]);
    }

    #[Test]
    public function a_member_is_never_its_own_counterparty(): void
    {
        $mine = $this->makeOrganization();
        $owner = $this->makeUser($mine, [RoleEnum::OWNER]);

        $this->actingAsUser($owner)
            ->getJson('/api/v1/counterparties/'.$mine->id)
            ->assertStatus(404);

        $this->actingAsUser($owner)
            ->putJson('/api/v1/counterparties/'.$mine->id.'/limits', ['gold_limit_mg' => 1])
            ->assertStatus(404);
    }

    #[Test]
    public function every_counterparty_endpoint_rejects_an_anonymous_caller(): void
    {
        /** @var Organization $organization */
        $organization = $this->makeOrganization();

        $this->getJson('/api/v1/counterparties')->assertStatus(401);
        $this->getJson('/api/v1/counterparties/'.$organization->id)->assertStatus(401);

        $response = $this->putJson('/api/v1/counterparties/'.$organization->id.'/settings', []);
        $response->assertStatus(401);
        $this->assertErrorEnvelope($response, 'AUTH_TOKEN_INVALID');
    }

    private function relations(): RelationService
    {
        return $this->app->make(RelationService::class);
    }
}
