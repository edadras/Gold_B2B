<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Feature;

use App\Modules\Webhook\Application\SignatureGenerator;
use App\Modules\Webhook\Application\WebhookDispatcher;
use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhook\Tests\WebhookTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * What actually goes over the wire — §3.9's four headers and the §3.8 body.
 *
 * Everything is asserted from the receiver's point of view: the test verifies
 * the signature the way the document tells a member to, rather than checking
 * that we called our own signer.
 */
final class DeliveryTest extends WebhookTestCase
{
    private const SECRET = 'whsec_a3f9c2b1deadbeefcafe0123456789abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
    }

    #[Test]
    public function a_delivery_carries_the_documented_headers_and_a_verifiable_signature(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $webhook = $this->webhook();

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::factory()->forWebhook((int) $webhook->getKey())->create();

        $this->attemptDelivery((int) $delivery->getKey());

        $signer = new SignatureGenerator;

        Http::assertSent(function (Request $request) use ($delivery, $signer): bool {
            self::assertSame('POST', $request->method());
            self::assertSame('https://hook.member-example.com/goldb2b', $request->url());

            // §3.9's headers.
            self::assertSame(
                $delivery->getAttribute('event_type'),
                $request->header(SignatureGenerator::EVENT_HEADER)[0],
            );
            self::assertSame(
                $delivery->getAttribute('event_id'),
                $request->header(SignatureGenerator::EVENT_ID_HEADER)[0],
            );
            self::assertSame('1', $request->header(SignatureGenerator::ATTEMPT_HEADER)[0]);

            $signature = $request->header(SignatureGenerator::HEADER)[0];
            self::assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $signature);

            // The receiver's own check, from §3.9 — against the raw body.
            self::assertTrue(
                $signer->verify(self::SECRET, $request->body(), $signature),
                'the signature must verify against the exact bytes that were sent',
            );

            // …and the body is the §3.8 envelope.
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($request->body(), true);
            self::assertSame($delivery->getAttribute('event_id'), $decoded['id']);
            self::assertSame('v1', $decoded['api_version']);

            return true;
        });

        $delivery->refresh();
        self::assertSame(DeliveryStatus::DELIVERED, $delivery->status);
        self::assertSame(200, $delivery->response_code);
        self::assertNotNull($delivery->delivered_at);
        self::assertNull($delivery->next_retry_at);
    }

    #[Test]
    public function the_attempt_header_counts_up_across_retries(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $webhook = $this->webhook();
        $delivery = WebhookDelivery::factory()->forWebhook((int) $webhook->getKey())->create();

        $this->attemptDelivery((int) $delivery->getKey());
        $this->attemptDelivery((int) $delivery->getKey());
        $this->attemptDelivery((int) $delivery->getKey());

        $seen = [];

        Http::assertSent(function (Request $request) use (&$seen): bool {
            $seen[] = $request->header(SignatureGenerator::ATTEMPT_HEADER)[0];

            return true;
        });

        self::assertSame(['1', '2', '3'], $seen);
    }

    /** §3.10 «پاسخ موفق: هر کد ۲xx» — and nothing else, including a redirect. */
    #[Test]
    public function a_redirect_is_a_failure_not_a_new_destination(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $webhook = $this->webhook();
        $delivery = WebhookDelivery::factory()->forWebhook((int) $webhook->getKey())->create();

        $this->attemptDelivery((int) $delivery->getKey());

        $delivery->refresh();
        self::assertSame(DeliveryStatus::FAILED, $delivery->status);
        self::assertSame(302, $delivery->response_code);
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_test_ping_is_queued_with_a_fresh_id_every_time(): void
    {
        $webhook = $this->webhook();
        $dispatcher = $this->app->make(WebhookDispatcher::class);

        $first = $dispatcher->sendTest($webhook);
        $second = $dispatcher->sendTest($webhook);

        self::assertNotSame($first->getAttribute('event_id'), $second->getAttribute('event_id'));
        self::assertSame(WebhookEventType::TEST_EVENT_TYPE, $first->getAttribute('event_type'));
        self::assertSame(2, WebhookDelivery::query()->count());
    }

    private function webhook(): Webhook
    {
        /** @var Webhook $webhook */
        $webhook = Webhook::factory()
            ->forOrganization(184)
            ->withUrl('https://hook.member-example.com/goldb2b')
            ->withSecret(self::SECRET)
            ->create();

        return $webhook;
    }
}
