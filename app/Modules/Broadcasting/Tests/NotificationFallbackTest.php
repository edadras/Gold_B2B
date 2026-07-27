<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Events\NotificationDelivered as Frame;
use App\Modules\Broadcasting\Listeners\BroadcastNotification;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * One alert, one frame.
 *
 * BroadcastNotification has two inputs. Notification's own
 * `NotificationDelivered` is the good one — an already-persisted, already
 * deduplicated row with the id the member's feed will show. The curated list of
 * raw domain events is a fallback for a deployment that runs without the
 * Notification module.
 *
 * Seven of the ten curated events are also handled by Notification. If both
 * paths ran, a member would get two frames for one alert, under two different
 * ids, and the client's deduplication (which keys on the id) would not collapse
 * them. So the fallback stands down whenever Notification is listening — and
 * that is the property worth a test, because the failure it prevents is
 * invisible in any single-module suite.
 */
final class NotificationFallbackTest extends BroadcastingTestCase
{
    #[Test]
    public function the_fallback_stands_down_when_notification_handles_the_event(): void
    {
        $event = new Doubles\SettlementOverdue(organizationId: 42, settlementId: 7);

        // Exactly what NotificationServiceProvider does for this event.
        Event::listen($event::class, 'App\Modules\Notification\Listeners\NotifyOnDomainEvent');

        Event::fake([Frame::class]);

        $this->app->make(BroadcastNotification::class)->handle($event);

        Event::assertNotDispatched(Frame::class);
    }

    #[Test]
    public function the_fallback_fires_when_nothing_else_will(): void
    {
        $event = new Doubles\SettlementOverdue(organizationId: 42, settlementId: 7);

        Event::fake([Frame::class]);

        $this->app->make(BroadcastNotification::class)->handle($event);

        Event::assertDispatched(
            Frame::class,
            static fn (Frame $frame): bool => $frame->organizationId === 42
                && $frame->code === 'SETTLEMENT_OVERDUE',
        );
    }

    #[Test]
    public function a_delivered_notification_always_goes_out(): void
    {
        // The good path is never suppressed: the row exists, the member's feed
        // has it, the socket must say so.
        $event = new Doubles\NotificationDelivered(
            notificationId: 9001,
            organizationId: 42,
            userId: 5,
            code: 'PAYMENT_REQUIRED',
            category: 'FINANCIAL',
            priority: 'CRITICAL',
            subject: 'پرداخت لازم است',
            body: 'مبلغ ۱۹٬۵۲۱٬۹۰۰٬۰۰۰ ریال',
        );

        Event::fake([Frame::class]);

        $this->app->make(BroadcastNotification::class)->handle($event);

        Event::assertDispatched(
            Frame::class,
            static fn (Frame $frame): bool => $frame->id === '9001'
                && $frame->code === 'PAYMENT_REQUIRED',
        );
    }
}
