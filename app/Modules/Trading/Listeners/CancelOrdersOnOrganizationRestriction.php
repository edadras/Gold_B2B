<?php

declare(strict_types=1);

namespace App\Modules\Trading\Listeners;

use App\Modules\Trading\Application\OrderExpiryService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A suspended or restricted member must not keep resting orders in the book.
 *
 * Identity fires OrganizationSuspended / OrganizationRestricted and refuses to
 * touch Trading's tables itself, which is the correct direction of the
 * dependency — so this listener does the cancelling and the reservations come
 * back to the member.
 *
 * Registered by STRING event name in TradingServiceProvider and typed as a bare
 * `object` here, because Identity is a separate module that may be absent from
 * a deployment slice. The payload is read defensively for the same reason: a
 * missing organizationId is logged and skipped rather than thrown, since an
 * exception in a listener would take down whatever fired the event.
 */
final readonly class CancelOrdersOnOrganizationRestriction
{
    public function __construct(private OrderExpiryService $orders) {}

    public function handle(object $event): void
    {
        $organizationId = $this->organizationId($event);

        if ($organizationId === null) {
            Log::warning('Organization status event carried no organization id', [
                'event' => $event::class,
            ]);

            return;
        }

        $reason = $this->reason($event);

        try {
            $cancelled = $this->orders->cancelAllForOrganization($organizationId, $reason);

            if ($cancelled > 0) {
                Log::info('Cancelled open orders after an organization status change', [
                    'organization_id' => $organizationId,
                    'cancelled' => $cancelled,
                    'reason' => $reason,
                ]);
            }
        } catch (Throwable $e) {
            // The member's status change has already happened; failing here
            // must not roll it back. Loud log, no rethrow.
            Log::error('Failed to cancel open orders after an organization status change', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function organizationId(object $event): ?int
    {
        foreach (['organizationId', 'organization_id', 'orgId'] as $name) {
            if (property_exists($event, $name) && is_int($event->{$name})) {
                return $event->{$name};
            }
        }

        return null;
    }

    private function reason(object $event): string
    {
        $suffix = property_exists($event, 'reason') && is_string($event->reason) && $event->reason !== ''
            ? ': '.$event->reason
            : '';

        $kind = str_contains($event::class, 'Suspended') ? 'suspended' : 'restricted';

        return 'organization '.$kind.$suffix;
    }
}
