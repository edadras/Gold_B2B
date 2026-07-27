<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCode;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/** §15.4 rule 2 — quiet hours apply to LOW and NORMAL only. */
final class QuietHoursTest extends NotificationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_high_priority_notification_still_goes_out_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 03:00:00', 'Asia/Tehran'));

        $treasurer = $this->recipient(1, ['TREASURER']);

        $this->setPreference($treasurer->id, Category::SETTLEMENT, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '08:00',
        ]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            params: ['amount' => '19,650,000,000', 'deadline' => '۱۷:۰۰'],
            subjectType: 'settlement',
            subjectId: 1,
        ));

        // HIGH sits between NORMAL and CRITICAL: a payment deadline is worth
        // waking someone for, even though it is not a default.
        self::assertTrue($result->usedChannel(Channel::PUSH->value));
    }

    #[Test]
    public function a_normal_priority_notification_is_silenced_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 03:00:00', 'Asia/Tehran'));

        $treasurer = $this->recipient(1, ['TREASURER']);

        $this->setPreference($treasurer->id, Category::SETTLEMENT, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '08:00',
        ]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_COMPLETED,
            organizationId: 1,
            params: ['reference' => 'STL-1'],
            subjectType: 'settlement',
            subjectId: 1,
        ));

        self::assertSame(1, $result->created());
        self::assertFalse($result->usedChannel(Channel::PUSH->value));
    }

    #[Test]
    public function the_same_notification_outside_quiet_hours_is_delivered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 10:00:00', 'Asia/Tehran'));

        $treasurer = $this->recipient(1, ['TREASURER']);

        $this->setPreference($treasurer->id, Category::SETTLEMENT, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '08:00',
        ]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_COMPLETED,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 2,
        ));

        self::assertTrue($result->usedChannel(Channel::PUSH->value));
    }

    #[Test]
    public function a_quiet_window_that_crosses_midnight_covers_both_sides_of_it(): void
    {
        $treasurer = $this->recipient(1, ['TREASURER']);

        $this->setPreference($treasurer->id, Category::SETTLEMENT, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '07:00',
        ]);

        $preferences = $this->preferences->for($treasurer->id, Category::SETTLEMENT);

        foreach (['23:30:00' => true, '02:00:00' => true, '06:59:00' => true, '07:00:00' => false, '12:00:00' => false, '21:59:00' => false] as $time => $expected) {
            self::assertSame(
                $expected,
                $preferences->isQuietAt(Carbon::parse('2026-03-05 '.$time, 'Asia/Tehran')),
                "quiet at {$time}?",
            );
        }
    }

    #[Test]
    public function quiet_hours_are_read_in_the_market_timezone_not_utc(): void
    {
        $treasurer = $this->recipient(1, ['TREASURER']);

        $this->setPreference($treasurer->id, Category::SETTLEMENT, [
            'quiet_hours_from' => '22:00',
            'quiet_hours_to' => '07:00',
        ]);

        $preferences = $this->preferences->for($treasurer->id, Category::SETTLEMENT);

        // 20:00 UTC is 23:30 in Tehran — quiet, even though the UTC clock says
        // early evening.
        self::assertTrue($preferences->isQuietAt(Carbon::parse('2026-03-05 20:00:00', 'UTC')));
        self::assertFalse($preferences->isQuietAt(Carbon::parse('2026-03-05 05:00:00', 'UTC')));
    }

    #[Test]
    public function a_user_who_never_opened_the_settings_screen_has_no_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 03:00:00', 'Asia/Tehran'));

        $this->recipient(1, ['TREASURER']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_COMPLETED,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 3,
        ));

        self::assertTrue($result->usedChannel(Channel::PUSH->value), 'defaults are in-app plus push');
    }
}
