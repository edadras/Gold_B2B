<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Contracts;

use App\Modules\Broadcasting\Domain\BroadcastEventName;

/**
 * The rate limiter in front of the aggregatable market events (§3.5).
 *
 * Keyed per event *and* per subject (the instrument code), because a busy
 * instrument must not starve a quiet one of its five quotes a second.
 */
interface BroadcastThrottle
{
    /**
     * True when this frame may be broadcast, false when it is superseded.
     *
     * An unthrottled event (`trade.executed`, `balance.updated`) always
     * returns true — the decision lives on BroadcastEventName, not here, so
     * there is no way for a caller to accidentally rate-limit a fill.
     */
    public function allow(BroadcastEventName $event, string $subject): bool;

    /** Test and operational hook: forget everything recorded for a subject. */
    public function clear(BroadcastEventName $event, string $subject): void;
}
