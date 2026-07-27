<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Feature;

use App\Modules\Webhook\Application\DeliverWebhookJob;
use App\Modules\Webhook\Console\DispatchDueDeliveriesCommand;
use App\Modules\Webhook\Domain\DeliveryStatus;
use App\Modules\Webhook\Domain\RetrySchedule;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Events\WebhookDisabled;
use App\Modules\Webhook\Events\WebhookFailing;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhook\Tests\WebhookTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * §3.10 — the retry ladder, the FAILING threshold and the DISABLED deadline.
 *
 * Time is frozen so `next_retry_at` can be compared exactly rather than
 * approximately, and the queue is faked so that stepping the ladder is
 * deliberate: each rung is one explicit call, not a cascade.
 */
final class RetryLadderTest extends WebhookTestCase
{
    /** §3.10's table, in seconds. Attempt 1 is «فوری». */
    private const LADDER = [0, 30, 120, 600, 3_600, 21_600, 86_400];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-30 10:00:00');
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_schedule_is_the_documented_ladder(): void
    {
        $schedule = new RetrySchedule;

        self::assertSame(self::LADDER, $schedule->delays());
        self::assertSame(7, $schedule->maxAttempts());

        self::assertSame(0, $schedule->delayBeforeAttempt(1));
        self::assertSame(30, $schedule->delayAfterFailures(1));
        self::assertSame(120, $schedule->delayAfterFailures(2));
        self::assertSame(600, $schedule->delayAfterFailures(3));
        self::assertSame(3_600, $schedule->delayAfterFailures(4));
        self::assertSame(21_600, $schedule->delayAfterFailures(5));
        self::assertSame(86_400, $schedule->delayAfterFailures(6));

        self::assertNull($schedule->delayAfterFailures(7), 'there is no eighth rung');
        self::assertTrue($schedule->isExhausted(7));
    }

    #[Test]
    public function a_failing_delivery_walks_the_exact_backoff_sequence_and_then_exhausts(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        [$webhook, $delivery] = $this->queuedDelivery();

        $observed = [];

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->attemptDelivery((int) $delivery->getKey());

            $delivery->refresh();

            self::assertSame($attempt, $delivery->attempts);

            $observed[] = $delivery->next_retry_at === null
                ? null
                : (int) Carbon::now()->diffInSeconds($delivery->next_retry_at, true);
        }

        // After failure 1 the next attempt is 30s away, after failure 2 it is
        // 2m away … and after failure 7 there is no next attempt at all.
        self::assertSame([30, 120, 600, 3_600, 21_600, 86_400, null], $observed);

        self::assertSame(DeliveryStatus::EXHAUSTED, $delivery->status);
        self::assertSame(500, $delivery->response_code);
        self::assertStringContainsString('HTTP 500', (string) $delivery->last_error);

        // Exactly seven requests were made — the ladder does not spend a rung
        // twice or skip one.
        Http::assertSentCount(7);

        $webhook->refresh();
        self::assertSame(7, $webhook->consecutive_failures);
    }

    #[Test]
    public function the_seventh_consecutive_failure_marks_the_webhook_failing_and_announces_it(): void
    {
        Event::fake([WebhookFailing::class, WebhookDisabled::class]);
        Http::fake(['*' => Http::response('', 503)]);

        [$webhook, $delivery] = $this->queuedDelivery();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->attemptDelivery((int) $delivery->getKey());
        }

        $webhook->refresh();
        self::assertSame(
            WebhookStatus::ACTIVE,
            $webhook->status,
            'six failures is not yet enough — §3.10 says seven',
        );
        Event::assertNotDispatched(WebhookFailing::class);

        $this->attemptDelivery((int) $delivery->getKey());

        $webhook->refresh();
        self::assertSame(WebhookStatus::FAILING, $webhook->status);
        self::assertSame(7, $webhook->consecutive_failures);
        self::assertNotNull($webhook->failing_since);

        // §3.10 «اعلان به عضو» — announced, not sent from here: this module may
        // not depend on Notification.
        Event::assertDispatched(
            WebhookFailing::class,
            static fn (WebhookFailing $e): bool => $e->webhookId === $webhook->id
                && $e->consecutiveFailures === 7
                && $e->organizationId === $webhook->organization_id,
        );

        // A FAILING endpoint is still attempted — the member's server may return.
        self::assertTrue($webhook->status->acceptsDeliveries());
    }

    #[Test]
    public function a_success_clears_the_failure_streak_and_revives_a_failing_endpoint(): void
    {
        // One stub whose answer changes: Http::fake() called twice keeps both
        // stubs and the first still wins, which would silently not test this.
        $status = 500;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });

        [$webhook, $delivery] = $this->queuedDelivery();

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->attemptDelivery((int) $delivery->getKey());
        }

        self::assertSame(WebhookStatus::FAILING, $webhook->refresh()->status);

        // A second event arrives and this time the receiver answers 204.
        $status = 204;

        $second = WebhookDelivery::factory()->forWebhook((int) $webhook->getKey())->create();
        $this->attemptDelivery((int) $second->getKey());

        $webhook->refresh();
        $second->refresh();

        self::assertSame(DeliveryStatus::DELIVERED, $second->status);
        self::assertSame(204, $second->response_code, 'any 2xx is a success, not only 200');
        self::assertNotNull($second->delivered_at);

        self::assertSame(WebhookStatus::ACTIVE, $webhook->status);
        self::assertSame(0, $webhook->consecutive_failures);
        self::assertNull($webhook->failing_since);
    }

    /** §3.10 «پس از ۷۲ ساعت وضعیت DISABLED». */
    #[Test]
    public function an_endpoint_failing_for_seventy_two_hours_is_disabled_and_announced(): void
    {
        Event::fake([WebhookDisabled::class]);
        Http::fake(['*' => Http::response('', 500)]);

        [$webhook, $delivery] = $this->queuedDelivery();

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->attemptDelivery((int) $delivery->getKey());
        }

        self::assertSame(WebhookStatus::FAILING, $webhook->refresh()->status);

        // Seventy-one hours later it is still only FAILING.
        Carbon::setTestNow(Carbon::now()->addHours(71));
        $this->artisan(DispatchDueDeliveriesCommand::class)->assertSuccessful();
        self::assertSame(WebhookStatus::FAILING, $webhook->refresh()->status);
        Event::assertNotDispatched(WebhookDisabled::class);

        // Seventy-three hours later the deadline has passed.
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->artisan(DispatchDueDeliveriesCommand::class)->assertSuccessful();

        $webhook->refresh();
        self::assertSame(WebhookStatus::DISABLED, $webhook->status);
        self::assertNotNull($webhook->disabled_at);
        self::assertFalse($webhook->status->acceptsDeliveries());

        Event::assertDispatched(
            WebhookDisabled::class,
            static fn (WebhookDisabled $e): bool => $e->webhookId === $webhook->id,
        );
    }

    #[Test]
    public function a_disabled_endpoint_is_never_attempted_again(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [$webhook, $delivery] = $this->queuedDelivery();

        $webhook->forceFill(['status' => WebhookStatus::DISABLED, 'disabled_at' => now()])->save();

        $this->attemptDelivery((int) $delivery->getKey());

        Http::assertNothingSent();
        self::assertSame(DeliveryStatus::QUEUED, $delivery->refresh()->status);
    }

    #[Test]
    public function the_next_attempt_is_queued_with_the_ladders_delay(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        [, $delivery] = $this->queuedDelivery();

        $this->attemptDelivery((int) $delivery->getKey());

        Queue::assertPushed(
            DeliverWebhookJob::class,
            static fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id
                && $job->delay === 30,
        );
    }

    /** @return array{0: Webhook, 1: WebhookDelivery} */
    private function queuedDelivery(): array
    {
        /** @var Webhook $webhook */
        $webhook = Webhook::factory()
            ->forOrganization(184)
            ->withUrl('https://hook.member-example.com/goldb2b')
            ->create();

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::factory()->forWebhook((int) $webhook->getKey())->create();

        return [$webhook, $delivery];
    }
}
