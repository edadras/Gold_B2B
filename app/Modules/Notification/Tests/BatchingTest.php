<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\DispatchResult;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Infrastructure\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §15.4 rule 3 — more than five same-code notifications inside five minutes
 * collapse into one aggregate.
 */
final class BatchingTest extends NotificationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_first_five_are_individual_and_the_sixth_collapses(): void
    {
        $this->recipient(1, ['TRADER']);

        for ($i = 1; $i <= 5; $i++) {
            $result = $this->fill($i);
            self::assertSame(1, $result->created(), "notification {$i} is its own row");
            self::assertSame([], $result->aggregatedUserIds);
        }

        $sixth = $this->fill(6);

        self::assertSame(0, $sixth->created());
        self::assertSame([1], $sixth->aggregatedUserIds);

        $aggregate = Notification::query()->whereNotNull('aggregate_count')->first();

        self::assertNotNull($aggregate);
        self::assertSame(6, $aggregate->aggregate_count);
        self::assertStringContainsString('6', $aggregate->body, 'the aggregate states how many it stands for');
        self::assertNull($aggregate->subject_id, 'an aggregate belongs to no single subject');
    }

    #[Test]
    public function further_notifications_keep_counting_on_the_same_aggregate(): void
    {
        $this->recipient(1, ['TRADER']);

        for ($i = 1; $i <= 10; $i++) {
            $this->fill($i);
        }

        $aggregates = Notification::query()->whereNotNull('aggregate_count')->get();

        self::assertCount(1, $aggregates, 'one aggregate, not one per overflow');
        self::assertSame(10, $aggregates[0]->aggregate_count);
        self::assertStringContainsString('10', $aggregates[0]->title);

        // Five individual rows plus the one aggregate.
        self::assertSame(6, Notification::query()->count());
    }

    #[Test]
    public function the_feed_shows_the_aggregate_in_place_of_the_rows_it_covers(): void
    {
        $this->recipient(1, ['TRADER']);

        for ($i = 1; $i <= 8; $i++) {
            $this->fill($i);
        }

        $feed = Notification::query()->feedFor(1)->get();

        self::assertCount(1, $feed);
        self::assertTrue($feed[0]->isAggregate());
        self::assertSame(8, $feed[0]->aggregate_count);
    }

    #[Test]
    public function a_notification_outside_the_window_starts_a_fresh_run(): void
    {
        $this->recipient(1, ['TRADER']);

        Carbon::setTestNow(Carbon::parse('2026-03-05 10:00:00'));

        for ($i = 1; $i <= 6; $i++) {
            $this->fill($i);
        }

        self::assertSame(1, Notification::query()->whereNotNull('aggregate_count')->count());

        // Six minutes later the window has passed: the burst is over and the
        // next fill is an ordinary notification again.
        Carbon::setTestNow(Carbon::parse('2026-03-05 10:06:00'));

        $result = $this->fill(7);

        self::assertSame(1, $result->created());
        self::assertSame([], $result->aggregatedUserIds);
    }

    #[Test]
    public function different_codes_do_not_batch_together(): void
    {
        $this->recipient(1, ['TRADER', 'OWNER']);

        for ($i = 1; $i <= 5; $i++) {
            $this->fill($i);
        }

        $other = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_CANCELLED,
            organizationId: 1,
            subjectType: 'order',
            subjectId: 99,
        ));

        self::assertSame(1, $other->created(), 'a different code has its own window');
        self::assertSame([], $other->aggregatedUserIds);
    }

    #[Test]
    public function critical_notifications_are_never_collapsed(): void
    {
        $this->recipient(1, ['OWNER']);

        for ($i = 1; $i <= 8; $i++) {
            $this->dispatcher->dispatch(new NotificationSpec(
                code: NotificationCode::SETTLEMENT_OVERDUE,
                organizationId: 1,
                params: ['reference' => 'STL-'.$i],
                subjectType: 'settlement',
                subjectId: $i,
            ));
        }

        // Each overdue settlement needs its own action, so none of them is
        // allowed to disappear into a counter.
        self::assertSame(8, Notification::query()->count());
        self::assertSame(0, DB::table('notifications')->whereNotNull('aggregate_count')->count());
    }

    private function fill(int $orderId): DispatchResult
    {
        return $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_FILLED,
            organizationId: 1,
            params: ['weight' => '300', 'price' => '78,500,000'],
            subjectType: 'order',
            subjectId: $orderId,
        ));
    }
}
