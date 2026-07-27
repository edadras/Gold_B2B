<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\DispatchResult;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Events\NotificationDelivered;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * The module announces what it persists.
 *
 * Without this event the only way to learn that a member has a new alert is to
 * poll the feed, which is why Broadcasting had to keep a private list of raw
 * domain events to push on the socket — a second, parallel notion of what a
 * notification is, with its own priorities and its own ids.
 *
 * The rule the tests below pin: one event per row actually written. Never for a
 * recipient the deduplicator skipped, never for one the batcher folded away,
 * and one per person when a role resolves to several.
 */
final class NotificationDeliveredEventTest extends NotificationTestCase
{
    /** @var list<NotificationDelivered> */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(NotificationDelivered::class, function (NotificationDelivered $event): void {
            $this->delivered[] = $event;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_delivered_notification_is_announced_with_its_row_id(): void
    {
        $this->recipient(1, ['TREASURER']);

        $result = $this->paymentRequired(88_231);

        $this->assertCount(1, $this->delivered);

        $event = $this->delivered[0];

        $this->assertSame($result->notificationIds[0], $event->notificationId);
        $this->assertSame(1, $event->userId);
        $this->assertSame(1, $event->organizationId);
        $this->assertSame(NotificationCode::PAYMENT_REQUIRED->value, $event->code);
        $this->assertSame(88_231, $event->subjectId);
        $this->assertNotSame('', $event->subject);
    }

    #[Test]
    public function one_event_per_recipient_not_per_dispatch(): void
    {
        $this->recipient(1, ['TREASURER']);
        $this->recipient(2, ['OWNER']);

        $this->paymentRequired(88_231);

        $this->assertCount(2, $this->delivered);
        $this->assertEqualsCanonicalizing([1, 2], array_map(
            static fn (NotificationDelivered $e): int => $e->userId,
            $this->delivered,
        ));
    }

    #[Test]
    public function a_deduplicated_recipient_is_not_announced_again(): void
    {
        $this->recipient(1, ['TREASURER']);

        $this->paymentRequired(88_231);
        $this->paymentRequired(88_231);

        // The second dispatch wrote nothing, so it must announce nothing —
        // otherwise the socket would show an alert the feed does not have.
        $this->assertCount(1, $this->delivered);
    }

    #[Test]
    public function the_priority_and_category_come_from_the_catalogue(): void
    {
        $this->recipient(1, ['TREASURER']);

        $this->paymentRequired(88_231);

        $event = $this->delivered[0];

        $this->assertSame(NotificationCode::PAYMENT_REQUIRED->category()->value, $event->category);
        $this->assertSame(NotificationCode::PAYMENT_REQUIRED->priority()->value, $event->priority);
    }

    private function paymentRequired(int $settlementId): DispatchResult
    {
        return $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            params: ['amount' => '19,521,900,000', 'settlement' => (string) $settlementId],
            subjectType: 'settlement',
            subjectId: $settlementId,
        ));
    }
}
