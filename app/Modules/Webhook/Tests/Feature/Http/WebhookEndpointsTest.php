<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Feature\Http;

use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhook\Tests\WebhookApiTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The eight management endpoints of §3.13, plus the two rules that matter most
 * about them: the secret is shown once, and a member sees only their own.
 */
#[Group('http')]
final class WebhookEndpointsTest extends WebhookApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
    }

    // ─────────────────────────── §3.7 registration ───────────────────────────

    #[Test]
    public function registration_returns_the_documented_envelope_with_the_secret_and_the_warning(): void
    {
        [$organization, $user] = $this->member();

        $response = $this->actingAsUser($user)->postJson('/api/v1/webhooks', [
            'url' => 'https://accounting.example.com/goldb2b/hook',
            'events' => ['trade.executed', 'settlement.completed', 'balance.updated'],
            'description' => 'اتصال نرم‌افزار حسابداری',
        ]);

        $response->assertStatus(201);
        $this->assertEnvelope($response);

        $response->assertJsonPath('data.url', 'https://accounting.example.com/goldb2b/hook');
        $response->assertJsonPath('data.status', WebhookStatus::ACTIVE->value);
        $response->assertJsonPath('data.events', ['trade.executed', 'settlement.completed', 'balance.updated']);

        // §3.7 «این تنها بار نمایش secret است».
        $secret = $response->json('data.secret');
        self::assertIsString($secret);
        self::assertStringStartsWith('whsec_', $secret);
        self::assertNotNull($response->json('meta.warning'));

        $webhook = Webhook::query()->sole();
        self::assertSame($organization->id, $webhook->organization_id);
    }

    #[Test]
    public function an_unknown_event_type_is_a_field_error(): void
    {
        [, $user] = $this->member();

        $this->actingAsUser($user)
            ->postJson('/api/v1/webhooks', [
                'url' => 'https://accounting.example.com/hook',
                'events' => ['trade.execued'],
            ])
            ->assertStatus(422);

        self::assertSame(0, Webhook::query()->count());
    }

    /**
     * The four destinations the brief names, through the real HTTP surface.
     * The full matrix lives in SsrfGuardTest; this asserts the endpoint reports
     * them as an ordinary validation failure with a stable code.
     */
    #[Test]
    public function unsafe_destinations_are_refused_by_the_endpoint(): void
    {
        [, $user] = $this->member();

        foreach ([
            'http://localhost',
            'http://169.254.169.254',
            'http://10.0.0.1',
            'http://accounting.example.com/hook',
        ] as $url) {
            $response = $this->actingAsUser($user)->postJson('/api/v1/webhooks', [
                'url' => $url,
                'events' => ['trade.executed'],
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath('error.code', 'WEBHOOK_URL_NOT_ALLOWED');
        }

        self::assertSame(0, Webhook::query()->count());
    }

    // ─────────────────────────── secret handling ───────────────────────────

    #[Test]
    public function the_secret_is_never_returned_again_and_is_not_stored_in_plaintext(): void
    {
        [, $user] = $this->member();

        $secret = (string) $this->actingAsUser($user)->postJson('/api/v1/webhooks', [
            'url' => 'https://accounting.example.com/hook',
            'events' => ['trade.executed'],
        ])->json('data.secret');

        $webhook = Webhook::query()->sole();

        // Not in the list …
        $list = $this->actingAsUser($user)->getJson('/api/v1/webhooks');
        $list->assertOk();
        $list->assertJsonMissingPath('data.0.secret');
        self::assertStringNotContainsString($secret, $list->getContent() ?: '');

        // … nor in the delivery history.
        $deliveries = $this->actingAsUser($user)->getJson('/api/v1/webhooks/'.$webhook->id.'/deliveries');
        $deliveries->assertOk();
        self::assertStringNotContainsString($secret, $deliveries->getContent() ?: '');

        // Stored as a SHA-256 digest for identification …
        $row = DB::table('webhooks')->where('id', $webhook->id)->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $secret), $row->secret_hash);
        self::assertTrue($webhook->secretMatches($secret));
        self::assertFalse($webhook->secretMatches($secret.'x'));

        // … and the signing copy is ciphertext, not the secret itself. HMAC is
        // symmetric, so the signer must be able to recover the key; encryption
        // under APP_KEY is what "never stored in the clear" means here.
        self::assertNotSame($secret, $row->secret_encrypted);
        self::assertStringNotContainsString($secret, (string) $row->secret_encrypted);
        self::assertSame($secret, $webhook->signingSecret());

        // The display hint is four characters and nothing more.
        self::assertSame(substr($secret, -4), $row->secret_last_four);
    }

    #[Test]
    public function rotating_the_secret_returns_a_new_one_once_and_invalidates_the_old(): void
    {
        [, $user] = $this->member();

        $original = (string) $this->actingAsUser($user)->postJson('/api/v1/webhooks', [
            'url' => 'https://accounting.example.com/hook',
            'events' => ['trade.executed'],
        ])->json('data.secret');

        $webhook = Webhook::query()->sole();

        $response = $this->actingAsUser($user)
            ->postJson('/api/v1/webhooks/'.$webhook->id.'/rotate-secret');

        $response->assertOk();
        $rotated = (string) $response->json('data.secret');

        self::assertNotSame($original, $rotated);
        self::assertStringStartsWith('whsec_', $rotated);
        self::assertNotNull($response->json('meta.warning'));

        $webhook->refresh();
        self::assertTrue($webhook->secretMatches($rotated));
        self::assertFalse($webhook->secretMatches($original), 'the old key must stop working immediately');
        self::assertNotNull($webhook->secret_rotated_at);
    }

    // ─────────────────────────── the rest of §3.13 ───────────────────────────

    #[Test]
    public function the_list_returns_only_the_callers_own_endpoints(): void
    {
        [$mine, $user] = $this->member();
        [$theirs] = $this->member();

        Webhook::factory()->forOrganization($mine->id)->count(2)->create();
        Webhook::factory()->forOrganization($theirs->id)->count(3)->create();

        $response = $this->actingAsUser($user)->getJson('/api/v1/webhooks');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function updating_changes_the_subscription_and_revives_a_disabled_endpoint(): void
    {
        [$organization, $user] = $this->member();

        $webhook = Webhook::factory()
            ->forOrganization($organization->id)
            ->status(WebhookStatus::DISABLED)
            ->create(['consecutive_failures' => 9, 'failing_since' => now()->subDays(4)]);

        $response = $this->actingAsUser($user)->putJson('/api/v1/webhooks/'.$webhook->id, [
            'events' => ['lot.ownership_transferred', 'dispute.opened'],
            'url' => 'https://new-endpoint.example.com/hook',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.events', ['lot.ownership_transferred', 'dispute.opened']);
        $response->assertJsonPath('data.url', 'https://new-endpoint.example.com/hook');
        $response->assertJsonPath('data.status', WebhookStatus::ACTIVE->value);
        $response->assertJsonPath('data.consecutive_failures', 0);
    }

    #[Test]
    public function deleting_removes_the_endpoint_and_its_history(): void
    {
        [$organization, $user] = $this->member();

        $webhook = Webhook::factory()->forOrganization($organization->id)->create();
        WebhookDelivery::factory()->forWebhook((int) $webhook->id)->count(3)->create();

        $this->actingAsUser($user)
            ->deleteJson('/api/v1/webhooks/'.$webhook->id)
            ->assertNoContent();

        self::assertSame(0, Webhook::query()->count());
        self::assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function the_test_ping_queues_a_delivery(): void
    {
        [$organization, $user] = $this->member();

        $webhook = Webhook::factory()->forOrganization($organization->id)->create();

        $response = $this->actingAsUser($user)
            ->postJson('/api/v1/webhooks/'.$webhook->id.'/test');

        $response->assertStatus(202);
        $response->assertJsonPath('data.event_type', WebhookEventType::TEST_EVENT_TYPE);
        $response->assertJsonPath('data.status', DeliveryStatus::QUEUED->value);

        // Not subscribable: a member cannot register for the test type.
        self::assertNull(WebhookEventType::tryFrom(WebhookEventType::TEST_EVENT_TYPE));
    }

    #[Test]
    public function a_disabled_endpoint_refuses_the_test_ping(): void
    {
        [$organization, $user] = $this->member();

        $webhook = Webhook::factory()
            ->forOrganization($organization->id)
            ->status(WebhookStatus::DISABLED)
            ->create();

        $this->actingAsUser($user)
            ->postJson('/api/v1/webhooks/'.$webhook->id.'/test')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WEBHOOK_DISABLED');
    }

    #[Test]
    public function the_delivery_history_matches_the_documented_shape(): void
    {
        [$organization, $user] = $this->member();

        $webhook = Webhook::factory()->forOrganization($organization->id)->create();
        WebhookDelivery::factory()->forWebhook((int) $webhook->id)->create([
            'status' => DeliveryStatus::DELIVERED,
            'attempts' => 1,
            'response_code' => 200,
            'response_time_ms' => 142,
            'delivered_at' => now(),
        ]);

        $response = $this->actingAsUser($user)
            ->getJson('/api/v1/webhooks/'.$webhook->id.'/deliveries');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'event_id', 'event_type', 'status', 'attempts', 'response_code', 'response_time_ms', 'delivered_at']],
        ]);
        $response->assertJsonPath('data.0.response_time_ms', 142);
    }

    #[Test]
    public function a_delivery_can_be_retried_by_hand_and_keeps_its_event_id(): void
    {
        [$organization, $user] = $this->member();

        $webhook = Webhook::factory()->forOrganization($organization->id)->create();
        $delivery = WebhookDelivery::factory()->forWebhook((int) $webhook->id)->create([
            'status' => DeliveryStatus::EXHAUSTED,
            'attempts' => 7,
            'response_code' => 500,
        ]);

        $eventId = $delivery->event_id;

        $response = $this->actingAsUser($user)
            ->postJson('/api/v1/webhook-deliveries/'.$delivery->id.'/retry');

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', DeliveryStatus::QUEUED->value);
        $response->assertJsonPath('data.event_id', $eventId);
        // §3.11: the replay carries the same id so the receiver can ignore it.
        $response->assertJsonPath('data.attempts', 7);
    }

    // ─────────────────────────────── tenancy ───────────────────────────────

    #[Test]
    public function organization_a_cannot_read_update_or_delete_organization_bs_webhook(): void
    {
        [, $attacker] = $this->member();
        [$victimOrg] = $this->member();

        $victimWebhook = Webhook::factory()->forOrganization($victimOrg->id)->create();
        $victimDelivery = WebhookDelivery::factory()->forWebhook((int) $victimWebhook->id)->create();

        $id = $victimWebhook->id;

        // Every one is 404, never 403: a 403 would confirm the id exists.
        $this->actingAsUser($attacker)->getJson("/api/v1/webhooks/{$id}/deliveries")->assertNotFound();
        $this->actingAsUser($attacker)->putJson("/api/v1/webhooks/{$id}", ['events' => ['trade.executed']])->assertNotFound();
        $this->actingAsUser($attacker)->deleteJson("/api/v1/webhooks/{$id}")->assertNotFound();
        $this->actingAsUser($attacker)->postJson("/api/v1/webhooks/{$id}/rotate-secret")->assertNotFound();
        $this->actingAsUser($attacker)->postJson("/api/v1/webhooks/{$id}/test")->assertNotFound();
        $this->actingAsUser($attacker)
            ->postJson("/api/v1/webhook-deliveries/{$victimDelivery->id}/retry")
            ->assertNotFound();

        // Nothing was touched.
        $victimWebhook->refresh();
        self::assertSame($victimOrg->id, $victimWebhook->organization_id);
        self::assertNull($victimWebhook->secret_rotated_at);
        self::assertSame(1, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_trader_may_not_manage_the_organisations_webhooks(): void
    {
        [$organization] = $this->member();
        $trader = $this->makeUser($organization, [RoleEnum::TRADER]);

        $this->actingAsUser($trader)
            ->postJson('/api/v1/webhooks', [
                'url' => 'https://accounting.example.com/hook',
                'events' => ['trade.executed'],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/webhooks')->assertUnauthorized();
        $this->postJson('/api/v1/webhooks', [])->assertUnauthorized();
    }

    /** @return array{0: Organization, 1: \App\Modules\Identity\Infrastructure\Models\User} */
    private function member(): array
    {
        $organization = $this->makeOrganization();

        return [$organization, $this->makeUser($organization, [RoleEnum::OWNER])];
    }
}
