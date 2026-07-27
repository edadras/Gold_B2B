<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\DispatchResult;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Infrastructure\Notification;
use App\Modules\Notification\Infrastructure\NotificationDelivery;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * §15.4 rule 4 — one event, at most one notification per user, keyed on
 * (code, subject_id, user_id).
 */
final class DeduplicationTest extends NotificationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_same_event_delivered_twice_creates_one_notification(): void
    {
        $this->recipient(1, ['TREASURER']);

        $first = $this->paymentRequired(88_231);
        $second = $this->paymentRequired(88_231);

        self::assertSame(1, $first->created());
        self::assertSame(0, $second->created());
        self::assertSame([1], $second->skippedUserIds);
        self::assertSame(1, Notification::query()->count());
    }

    #[Test]
    public function a_different_subject_is_a_different_event(): void
    {
        $this->recipient(1, ['TREASURER']);

        $this->paymentRequired(88_231);
        $other = $this->paymentRequired(88_232);

        self::assertSame(1, $other->created());
        self::assertSame(2, Notification::query()->count());
    }

    #[Test]
    public function deduplication_is_per_user(): void
    {
        $this->recipient(1, ['TREASURER']);
        $this->recipient(2, ['OWNER']);

        $first = $this->paymentRequired(88_231);

        self::assertSame(2, $first->created(), 'both role holders are notified');

        // A retry reaches neither of them twice.
        $retry = $this->paymentRequired(88_231);

        self::assertSame(0, $retry->created());
        self::assertEqualsCanonicalizing([1, 2], $retry->skippedUserIds);
    }

    #[Test]
    public function an_event_without_a_subject_cannot_be_deduplicated(): void
    {
        $this->recipient(1, ['OWNER']);

        // Two unrelated logins are two events, and there is no subject id to
        // tell them apart — suppressing the second would hide a real one.
        $first = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::NEW_LOGIN,
            organizationId: 1,
        ));
        $second = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::NEW_LOGIN,
            organizationId: 1,
        ));

        self::assertSame(1, $first->created());
        self::assertSame(1, $second->created());
    }

    #[Test]
    public function the_same_subject_is_notifiable_again_beyond_the_window(): void
    {
        $this->recipient(1, ['TREASURER']);

        Carbon::setTestNow(Carbon::parse('2026-03-05 10:00:00'));
        $this->paymentRequired(88_231);

        // A reminder the next day is a new event, not a duplicate.
        Carbon::setTestNow(Carbon::parse('2026-03-06 11:00:00'));
        $reminder = $this->paymentRequired(88_231);

        self::assertSame(1, $reminder->created());
    }

    #[Test]
    public function deduplication_runs_before_delivery_so_a_duplicate_costs_nothing(): void
    {
        $this->recipient(1, ['OWNER']);

        $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_OVERDUE,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 5,
        ));

        $deliveriesAfterFirst = NotificationDelivery::query()->count();

        $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_OVERDUE,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 5,
        ));

        self::assertSame(
            $deliveriesAfterFirst,
            NotificationDelivery::query()->count(),
            'a suppressed duplicate must not send a second SMS',
        );
    }

    private function paymentRequired(int $settlementId): DispatchResult
    {
        return $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            params: ['amount' => '19,650,000,000', 'deadline' => '۱۷:۰۰'],
            subjectType: 'settlement',
            subjectId: $settlementId,
        ));
    }
}
