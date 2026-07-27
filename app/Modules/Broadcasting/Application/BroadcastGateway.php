<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Application;

use App\Modules\Broadcasting\Contracts\BroadcastThrottle;
use App\Modules\Broadcasting\Contracts\ThrottledBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The single place a broadcast event leaves this module.
 *
 * Everything funnels through emit() so that the throttle cannot be bypassed by
 * a listener that calls event() directly, and so that "what did we decide not
 * to send?" has one answer. The rule is one line and reads the same way it is
 * specified in §3.5: an event is dropped only if it declares itself droppable.
 *
 * ORDERING NOTE. Dispatching a ShouldBroadcast event puts a BroadcastEvent job
 * on the queue; it does not open a socket inline. That matters for AGENT_BRIEF
 * rule 3 — no broadcasts inside a DB transaction — but the rule is satisfied
 * upstream anyway: every domain event this module listens to is already fired
 * after commit by the module that owns it.
 */
final class BroadcastGateway
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly BroadcastThrottle $throttle,
    ) {}

    /**
     * @return bool true when the frame went out, false when it was superseded
     */
    public function emit(ShouldBroadcast $event): bool
    {
        if ($event instanceof ThrottledBroadcast
            && ! $this->throttle->allow($event->throttleEvent(), $event->throttleSubject())) {
            return false;
        }

        $this->events->dispatch($event);

        return true;
    }
}
