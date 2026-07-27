<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Infrastructure;

use App\Modules\Broadcasting\Contracts\BroadcastThrottle;
use App\Modules\Broadcasting\Domain\BroadcastEventName;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * The §3.5 rate limiter, in Redis because the ceiling is per instrument across
 * the whole cluster, not per PHP worker.
 *
 * TWO GATES, IN THIS ORDER:
 *
 *   1. a 100ms coalescing window — `SET key 1 NX PX 100`. The first frame in a
 *      window wins and every other frame in that window is superseded. This is
 *      the "aggregated" half of «تجمیع تغییرات»: the client gets the newest
 *      state at the top of each window rather than every intermediate one.
 *
 *   2. a per-second ceiling — INCR on a key stamped with the current second,
 *      compared against BroadcastEventName::maxPerSecond().
 *
 * Gate 1 alone already yields ≤10/sec, which is exactly depth.updated's
 * budget. Gate 2 is what pulls quote.updated down to 5. Both are needed: gate
 * 1 without gate 2 cannot express 5, and gate 2 without gate 1 would let five
 * frames go out inside one millisecond and then nothing for 999.
 *
 * Neither gate is a Lua script. Gate 1's `SET NX` is itself the atomic
 * decision — two workers racing inside the same window cannot both win it —
 * and gate 2 runs only for the one caller that already won gate 1, so the two
 * commands never interleave in a way that could overshoot.
 *
 * FAIL-OPEN, DELIBERATELY. If Redis is unreachable the throttle allows the
 * broadcast. The failure mode of allowing is a chatty socket; the failure mode
 * of denying is a market data feed that silently stops during a Redis blip and
 * a trader staring at a frozen book. Unthrottled events never reach Redis at
 * all, so this decision cannot affect a fill or a balance move either way.
 */
final class RedisBroadcastThrottle implements BroadcastThrottle
{
    /** Windows cleared by clear(): 20 * 100ms covers two whole seconds. */
    private const CLEAR_WINDOW_LOOKBACK = 20;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection = 'default',
        private readonly string $prefix = 'goldb2b:bcast:throttle',
        private readonly int $windowMs = 100,
    ) {}

    public function allow(BroadcastEventName $event, string $subject): bool
    {
        $maxPerSecond = $event->maxPerSecond();

        if ($maxPerSecond === null) {
            return true;
        }

        $nowMs = $this->nowMs();

        try {
            $connection = $this->redis->connection($this->connection);

            $windowKey = $this->key($event, $subject, 'w'.intdiv($nowMs, $this->window()));

            // NX: only the first caller inside this 100ms window gets true.
            $claimed = $connection->set($windowKey, '1', 'PX', $this->window(), 'NX');

            if (! $claimed) {
                return false;
            }

            $secondKey = $this->key($event, $subject, 's'.intdiv($nowMs, 1000));
            $count = (int) $connection->incr($secondKey);

            if ($count === 1) {
                $connection->pexpire($secondKey, 1000);
            }

            return $count <= $maxPerSecond;
        } catch (Throwable) {
            return true;
        }
    }

    public function clear(BroadcastEventName $event, string $subject): void
    {
        try {
            $connection = $this->redis->connection($this->connection);
            $nowMs = $this->nowMs();

            $second = intdiv($nowMs, 1000);
            $window = intdiv($nowMs, $this->window());

            // Enumerated rather than KEYS-globbed: KEYS is O(n) over the whole
            // keyspace and returns names with the connection prefix already
            // applied, which DEL would then apply a second time.
            $keys = [
                $this->key($event, $subject, 's'.$second),
                $this->key($event, $subject, 's'.($second - 1)),
            ];

            for ($i = 0; $i <= self::CLEAR_WINDOW_LOOKBACK; $i++) {
                $keys[] = $this->key($event, $subject, 'w'.($window - $i));
            }

            $connection->del(...$keys);
        } catch (Throwable) {
            // Clearing is an operational convenience; never break a caller.
        }
    }

    /**
     * Wall-clock milliseconds as an integer.
     *
     * gettimeofday() rather than microtime(true) so the arithmetic never
     * touches a float, and wall clock rather than hrtime() because hrtime's
     * origin is per-process: two app servers must land in the same 100ms
     * window for a cluster-wide ceiling to mean anything.
     */
    private function nowMs(): int
    {
        /** @var array{sec: int, usec: int} $time */
        $time = gettimeofday();

        return $time['sec'] * 1000 + intdiv($time['usec'], 1000);
    }

    private function window(): int
    {
        return max(1, $this->windowMs);
    }

    private function key(BroadcastEventName $event, string $subject, string $slot): string
    {
        return sprintf('%s:%s:%s:%s', $this->prefix, $event->value, $subject, $slot);
    }
}
