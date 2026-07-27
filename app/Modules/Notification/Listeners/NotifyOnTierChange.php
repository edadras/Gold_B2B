<?php

declare(strict_types=1);

namespace App\Modules\Notification\Listeners;

use App\Modules\Notification\Contracts\Notifier;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\NotificationCode;

/**
 * TIER_UPGRADED (§15.2), driven by the Reputation module's promotion event.
 *
 * Subscribed by name like every other cross-module reaction — Reputation is a
 * sibling leaf, not a dependency.
 *
 * A demotion is deliberately not wired to a catalogue code: §14.4 requires the
 * member to be told the reason and the route back, which is a written message
 * from a compliance officer, not a one-line badge notification.
 */
final class NotifyOnTierChange
{
    private const PROMOTED = 'App\Modules\Reputation\Events\TierPromoted';

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(object $event): void
    {
        if ($event::class !== self::PROMOTED) {
            return;
        }

        if (! property_exists($event, 'organizationId') || ! property_exists($event, 'toTier')) {
            return;
        }

        $organizationId = (int) $event->organizationId;

        if ($organizationId <= 0) {
            return;
        }

        $this->notifier->dispatch(new NotificationSpec(
            code: NotificationCode::TIER_UPGRADED,
            organizationId: $organizationId,
            params: ['tier' => (string) $event->toTier],
            subjectType: 'organization',
            subjectId: $organizationId,
        ));
    }
}
