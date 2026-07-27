<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Application\ChannelRegistry;
use App\Modules\Notification\Contracts\DeliveryOutcome;
use App\Modules\Notification\Contracts\NotificationChannel;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Contracts\OutboundMessage;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Infrastructure\Channels\InAppChannel;
use App\Modules\Notification\Infrastructure\Channels\LogChannel;
use App\Modules\Notification\Infrastructure\Notification;
use App\Modules\Notification\Infrastructure\NotificationDelivery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Drivers sit behind one interface and are swapped through config. */
final class ChannelDriverTest extends NotificationTestCase
{
    #[Test]
    public function the_in_app_driver_writes_the_notification_row(): void
    {
        $outcome = (new InAppChannel)->send(new OutboundMessage(
            organizationId: 1,
            userId: 7,
            code: NotificationCode::GOLD_RESERVED->value,
            category: 'ASSET',
            priority: 'NORMAL',
            title: 'رزرو طلا',
            body: '۲۵۰ گرم طلای شما رزرو شد',
            destination: '',
        ));

        self::assertSame(DeliveryOutcome::SENT, $outcome->status);
        self::assertNotNull($outcome->notificationId);

        $notification = Notification::query()->findOrFail($outcome->notificationId);

        self::assertSame(7, $notification->user_id);
        self::assertSame('GOLD_RESERVED', $notification->code);
        self::assertNull($notification->read_at);
        self::assertFalse($notification->isAggregate());
    }

    #[Test]
    public function the_local_drivers_are_log_channels_by_default(): void
    {
        $registry = $this->app->make(ChannelRegistry::class);

        self::assertInstanceOf(InAppChannel::class, $registry->for(Channel::IN_APP));

        foreach ([Channel::PUSH, Channel::SMS, Channel::EMAIL, Channel::WEBHOOK] as $channel) {
            $driver = $registry->for($channel);

            self::assertInstanceOf(LogChannel::class, $driver);
            self::assertSame($channel, $driver->channel(), 'the driver knows which channel it stands in for');
        }
    }

    #[Test]
    public function a_driver_can_be_swapped_through_config_alone(): void
    {
        config(['goldb2b.notification.drivers.SMS' => RecordingChannel::class]);
        RecordingChannel::$sent = [];

        $this->recipient(1, ['OWNER']);

        $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_OVERDUE,
            organizationId: 1,
            params: ['reference' => 'STL-1'],
            subjectType: 'settlement',
            subjectId: 1,
        ));

        self::assertCount(1, RecordingChannel::$sent);
        self::assertSame('SETTLEMENT_OVERDUE', RecordingChannel::$sent[0]->code);
        // No dispatch rule moved with the driver.
        self::assertSame('CRITICAL', RecordingChannel::$sent[0]->priority);
    }

    #[Test]
    public function a_driver_that_throws_is_recorded_as_failed_rather_than_breaking_the_dispatch(): void
    {
        config(['goldb2b.notification.drivers.PUSH' => ExplodingChannel::class]);

        $this->recipient(1, ['TRADER']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_FILLED,
            organizationId: 1,
            params: ['weight' => '300', 'price' => '78,500,000'],
            subjectType: 'order',
            subjectId: 1,
        ));

        // The feed entry still exists — a provider outage must not lose the
        // notification itself.
        self::assertSame(1, $result->created());

        $delivery = NotificationDelivery::query()->where('channel', 'PUSH')->firstOrFail();

        self::assertSame(DeliveryOutcome::FAILED, $delivery->status);
        self::assertStringContainsString('provider unreachable', (string) $delivery->error);
    }

    #[Test]
    public function push_fans_out_over_every_registered_device(): void
    {
        $this->recipient(1, ['TRADER'], pushTokens: ['device-a', 'device-b', 'device-c']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_FILLED,
            organizationId: 1,
            subjectType: 'order',
            subjectId: 1,
        ));

        self::assertSame(3, $result->deliveriesOn(Channel::PUSH->value));

        $destinations = NotificationDelivery::query()->where('channel', 'PUSH')->pluck('destination')->all();

        self::assertCount(3, array_unique($destinations), 'one delivery row per device');
    }

    #[Test]
    public function destinations_are_hashed_before_they_are_stored(): void
    {
        $hashed = OutboundMessage::hashDestination(Channel::SMS, '09121234567');

        self::assertStringStartsWith('sms:', $hashed);
        self::assertStringNotContainsString('0912', $hashed);
        self::assertSame($hashed, OutboundMessage::hashDestination(Channel::SMS, '09121234567'), 'stable');
        self::assertNotSame($hashed, OutboundMessage::hashDestination(Channel::SMS, '09121234568'));
    }
}

/** A driver that keeps what it was handed, standing in for a real provider. */
final class RecordingChannel implements NotificationChannel
{
    /** @var list<OutboundMessage> */
    public static array $sent = [];

    public function channel(): Channel
    {
        return Channel::SMS;
    }

    public function send(OutboundMessage $message): DeliveryOutcome
    {
        self::$sent[] = $message;

        return DeliveryOutcome::sent('recording:'.count(self::$sent));
    }
}

/** A provider having a bad day. */
final class ExplodingChannel implements NotificationChannel
{
    public function channel(): Channel
    {
        return Channel::PUSH;
    }

    public function send(OutboundMessage $message): DeliveryOutcome
    {
        throw new RuntimeException('provider unreachable');
    }
}
