<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Contracts\BroadcastThrottle;
use App\Modules\Broadcasting\Domain\BroadcastEventName;
use App\Modules\Broadcasting\Infrastructure\RedisBroadcastThrottle;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * §3.5: depth.updated at most 10/sec, quote.updated at most 5/sec, changes
 * aggregated into a 100ms window.
 *
 * TWO LEVELS, ON PURPOSE. The arithmetic is tested against the in-process
 * throttle with a frozen clock, because "50 frames inside one window" is only
 * meaningful if the window cannot advance underneath the assertion. The Redis
 * implementation is then tested against a real Redis, because SET NX PX is the
 * part that actually has to be atomic across workers and a fake would prove
 * nothing about it.
 */
final class ThrottleTest extends BroadcastingTestCase
{
    #[Test]
    public function fifty_rapid_depth_changes_collapse_to_one_frame_inside_the_window(): void
    {
        $throttle = $this->useArrayThrottle(windowMs: 100);
        $throttle->freezeAt(1_700_000_000_000);

        $allowed = 0;

        for ($i = 0; $i < 50; $i++) {
            if ($throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-995-T0')) {
                $allowed++;
            }
        }

        self::assertSame(
            1,
            $allowed,
            '50 changes inside one 100ms window are one aggregated frame, not 50',
        );
    }

    #[Test]
    public function fifty_rapid_depth_changes_spread_over_a_second_stay_within_ten(): void
    {
        $throttle = $this->useArrayThrottle(windowMs: 100);
        $throttle->freezeAt(1_700_000_000_000);

        $allowed = 0;

        // 50 changes spread evenly across one second: 20ms apart, so five land
        // in each 100ms window and only the first of each five survives.
        for ($i = 0; $i < 50; $i++) {
            if ($throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-995-T0')) {
                $allowed++;
            }

            $throttle->advance(20);
        }

        self::assertLessThanOrEqual(
            10,
            $allowed,
            'depth.updated is capped at 10 per second per instrument',
        );
        self::assertGreaterThan(1, $allowed, 'and it is not capped at one — the window does move on');
    }

    #[Test]
    public function quote_updates_are_capped_at_five_per_second(): void
    {
        $throttle = $this->useArrayThrottle(windowMs: 100);
        $throttle->freezeAt(1_700_000_000_000);

        $allowed = 0;

        for ($i = 0; $i < 50; $i++) {
            if ($throttle->allow(BroadcastEventName::QUOTE_UPDATED, 'GOLD-995-T0')) {
                $allowed++;
            }

            $throttle->advance(20);
        }

        self::assertSame(
            5,
            $allowed,
            'the 100ms window alone would allow 10; the per-second ceiling is what makes it 5',
        );
    }

    /**
     * The bucket is per instrument. A busy instrument must not consume a quiet
     * one's budget, which is why the key carries the subject.
     */
    #[Test]
    public function instruments_do_not_share_a_budget(): void
    {
        $throttle = $this->useArrayThrottle(windowMs: 100);
        $throttle->freezeAt(1_700_000_000_000);

        // Exhaust one instrument's window entirely.
        for ($i = 0; $i < 20; $i++) {
            $throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-995-T0');
        }

        self::assertTrue(
            $throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-750-T1'),
            'a different instrument has its own window',
        );
    }

    /** And the same for the event: depth and quote are separate buckets. */
    #[Test]
    public function events_do_not_share_a_budget(): void
    {
        $throttle = $this->useArrayThrottle(windowMs: 100);
        $throttle->freezeAt(1_700_000_000_000);

        self::assertTrue($throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-995-T0'));
        self::assertFalse($throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-995-T0'));

        self::assertTrue(
            $throttle->allow(BroadcastEventName::QUOTE_UPDATED, 'GOLD-995-T0'),
            'the quote budget is untouched by the depth frames',
        );
    }

    // --- the real driver ---------------------------------------------------

    #[Test]
    public function the_redis_driver_enforces_the_same_window(): void
    {
        $throttle = $this->redisThrottle();

        $subject = 'GOLD-995-T0-'.bin2hex(random_bytes(4));
        $throttle->clear(BroadcastEventName::DEPTH_UPDATED, $subject);

        $allowed = 0;

        // No sleeping: 50 iterations of two Redis round trips finish well
        // inside 100ms, so this is genuinely "a burst inside one window".
        for ($i = 0; $i < 50; $i++) {
            if ($throttle->allow(BroadcastEventName::DEPTH_UPDATED, $subject)) {
                $allowed++;
            }
        }

        self::assertLessThanOrEqual(10, $allowed, 'never above the per-second ceiling');
        self::assertGreaterThanOrEqual(1, $allowed, 'the first frame always goes out');

        $throttle->clear(BroadcastEventName::DEPTH_UPDATED, $subject);
    }

    /**
     * Redis being down must not silence the market feed. The alternative —
     * failing closed — turns a cache blip into a frozen order book on every
     * connected client, which is the worse of two bad outcomes.
     */
    #[Test]
    public function an_unreachable_redis_fails_open(): void
    {
        $throttle = new RedisBroadcastThrottle(
            redis: $this->app->make(RedisFactory::class),
            connection: 'a-connection-that-does-not-exist',
        );

        self::assertTrue($throttle->allow(BroadcastEventName::DEPTH_UPDATED, 'GOLD-995-T0'));
    }

    #[Test]
    public function the_redis_driver_is_the_default_binding(): void
    {
        self::assertInstanceOf(
            RedisBroadcastThrottle::class,
            $this->app->make(BroadcastThrottle::class),
            'the throttle is cluster-wide in production, so Redis is the default',
        );
    }

    private function redisThrottle(): RedisBroadcastThrottle
    {
        $throttle = new RedisBroadcastThrottle(
            redis: $this->app->make(RedisFactory::class),
            prefix: 'goldb2b:bcast:test',
        );

        try {
            $this->app->make(RedisFactory::class)->connection()->ping();
        } catch (Throwable $e) {
            self::markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }

        return $throttle;
    }
}
