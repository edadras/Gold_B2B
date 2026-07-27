<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Contracts;

use App\Modules\Broadcasting\Domain\BroadcastEventName;

/**
 * Marks a broadcast event as droppable, and says what it is rate-limited
 * against.
 *
 * OPT-IN, NOT OPT-OUT — the whole point. BroadcastGateway throttles an event
 * if and only if it implements this interface, so `trade.executed` and
 * `balance.updated` are unthrottleable by construction rather than by a
 * conditional somebody could invert during a refactor. To make an event
 * droppable you have to write it down here; to keep one guaranteed you do
 * nothing at all.
 */
interface ThrottledBroadcast
{
    public function throttleEvent(): BroadcastEventName;

    /** The bucket: an instrument code, so a busy market cannot starve a quiet one. */
    public function throttleSubject(): string;
}
