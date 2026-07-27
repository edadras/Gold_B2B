<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** §15.4 rule 1 — CRITICAL always sends, overriding preferences and quiet hours. */
final class CriticalPriorityTest extends NotificationTestCase
{
    #[Test]
    public function a_critical_notification_ignores_every_channel_the_user_switched_off(): void
    {
        $owner = $this->recipient(1, ['OWNER']);

        // The user muted this category as far as the settings screen allows.
        $this->setPreference($owner->id, Category::SETTLEMENT, [
            'in_app' => false,
            'push' => false,
            'sms' => false,
            'email' => false,
        ]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_OVERDUE,
            organizationId: 1,
            params: ['reference' => 'STL-88231'],
            subjectType: 'settlement',
            subjectId: 88_231,
        ));

        self::assertSame(1, $result->created(), 'the in-app row is written regardless');
        self::assertTrue($result->usedChannel(Channel::PUSH->value));
        self::assertTrue($result->usedChannel(Channel::SMS->value));
    }

    #[Test]
    public function a_critical_notification_ignores_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 02:30:00', 'Asia/Tehran'));

        $owner = $this->recipient(1, ['OWNER']);

        $this->setPreference($owner->id, Category::SETTLEMENT, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '08:00',
        ]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_DEFAULTED,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 88_231,
        ));

        self::assertTrue($result->usedChannel(Channel::PUSH->value));
        self::assertTrue($result->usedChannel(Channel::SMS->value));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_normal_notification_at_the_same_hour_stays_silent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 02:30:00', 'Asia/Tehran'));

        $trader = $this->recipient(1, ['TRADER']);

        $this->setPreference($trader->id, Category::TRADING, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '08:00',
        ]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_PLACED,
            organizationId: 1,
            params: ['side' => 'فروش', 'weight' => '300'],
            subjectType: 'order',
            subjectId: 5,
        ));

        self::assertSame(1, $result->created(), 'still visible in the feed');
        self::assertSame([], $result->channelCounts, 'but nothing left the building');

        Carbon::setTestNow();
    }

    #[Test]
    public function critical_deliveries_are_recorded_for_audit(): void
    {
        $this->recipient(1, ['OWNER']);

        $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::DISPUTE_OPENED_AGAINST,
            organizationId: 1,
            params: ['hours' => 24],
            subjectType: 'dispute',
            subjectId: 9,
        ));

        $rows = DB::table('notification_deliveries')->get();

        self::assertGreaterThanOrEqual(2, $rows->count());

        foreach ($rows as $row) {
            self::assertSame('SENT', $row->status);
            // §15.3: the destination is a hash, never the raw phone number.
            self::assertStringNotContainsString('0912', $row->destination);
            self::assertMatchesRegularExpression('/^(push|sms|email|webhook):[0-9a-f]{32}$/', $row->destination);
        }
    }
}
