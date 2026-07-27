<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Infrastructure;

use App\Modules\Broadcasting\Contracts\BroadcastThrottle;
use App\Modules\Broadcasting\Domain\BroadcastEventName;

/**
 * In-process throttle with the same two gates as RedisBroadcastThrottle.
 *
 * NOT a production driver: state dies with the PHP worker, so a fleet of eight
 * workers would allow eight times the ceiling. It exists so a test can pin the
 * *arithmetic* of the window without a live Redis, and so a single-process
 * console command (an order-book replayer, say) can throttle without one.
 */
final class ArrayBroadcastThrottle implements BroadcastThrottle
{
    /** @var array<string, int> window slot => 1 */
    private array $windows = [];

    /** @var array<string, int> second slot => count */
    private array $seconds = [];

    private ?int $frozenMs = null;

    public function __construct(private readonly int $windowMs = 100) {}

    public function allow(BroadcastEventName $event, string $subject): bool
    {
        $maxPerSecond = $event->maxPerSecond();

        if ($maxPerSecond === null) {
            return true;
        }

        $nowMs = $this->nowMs();
        $window = max(1, $this->windowMs);

        $windowKey = $event->value.':'.$subject.':w'.intdiv($nowMs, $window);

        if (isset($this->windows[$windowKey])) {
            return false;
        }

        $this->windows[$windowKey] = 1;

        $secondKey = $event->value.':'.$subject.':s'.intdiv($nowMs, 1000);
        $count = ($this->seconds[$secondKey] ?? 0) + 1;
        $this->seconds[$secondKey] = $count;

        return $count <= $maxPerSecond;
    }

    public function clear(BroadcastEventName $event, string $subject): void
    {
        $prefix = $event->value.':'.$subject.':';

        foreach (array_keys($this->windows) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->windows[$key]);
            }
        }

        foreach (array_keys($this->seconds) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->seconds[$key]);
            }
        }
    }

    /**
     * Pins the clock so a test can drive 50 frames through one window without
     * racing a real 100ms. Pass null to go back to the wall clock.
     */
    public function freezeAt(?int $milliseconds): void
    {
        $this->frozenMs = $milliseconds;
    }

    public function advance(int $milliseconds): void
    {
        $this->frozenMs = ($this->frozenMs ?? $this->wallMs()) + $milliseconds;
    }

    private function nowMs(): int
    {
        return $this->frozenMs ?? $this->wallMs();
    }

    private function wallMs(): int
    {
        /** @var array{sec: int, usec: int} $time */
        $time = gettimeofday();

        return $time['sec'] * 1000 + intdiv($time['usec'], 1000);
    }
}
