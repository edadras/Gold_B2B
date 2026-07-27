<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;
use Illuminate\Support\Facades\Event;

/**
 * Live notification delivery on `private-org.{orgId}.notification` (§3.2).
 *
 * TWO INPUTS, ONE OUTPUT.
 *
 * 1. `App\Modules\Notification\Events\NotificationDelivered`, by string name.
 *    This is the good path: the row is already deduplicated, already targeted
 *    at the right people by role, already in the member's feed. Broadcasting
 *    just puts it on the socket.
 *
 * 2. A curated list of raw domain events, for a deployment slice that runs
 *    without the Notification module. It is a *fallback*: if Notification is
 *    installed and listening for an event, that event is skipped here, because
 *    a NotificationDelivered is coming for it and a member must not receive the
 *    same alert twice under two different ids. See `notificationHandles()`.
 *
 * NO RENDERED TEXT. The frame carries a `code` and a small `data` map, not a
 * Persian sentence: §3.5 forbids `_display` fields on the socket and says the
 * client formats for itself. That also keeps this listener out of the business
 * of duplicating Notification's message catalogue, which it must not import.
 *
 * DEDUPLICATION IS THE CLIENT'S. `id` is stable for a given (code, subject)
 * pair, so a member receiving both this frame and a later REST poll collapses
 * them rather than showing the alert twice.
 */
final class BroadcastNotification
{
    /**
     * Domain event basename → [notification code, priority, id-bearing property].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CURATED = [
        'OrderRejected' => ['ORDER_REJECTED', 'HIGH', 'orderId'],
        'SettlementOverdue' => ['SETTLEMENT_OVERDUE', 'CRITICAL', 'settlementId'],
        'SettlementDefaulted' => ['SETTLEMENT_DEFAULTED', 'CRITICAL', 'settlementId'],
        'SettlementReversed' => ['SETTLEMENT_REVERSED', 'CRITICAL', 'settlementId'],
        'AssayVarianceDetected' => ['ASSAY_VARIANCE', 'HIGH', 'lotId'],
        'BalanceDiscrepancyDetected' => ['BALANCE_DISCREPANCY', 'CRITICAL', 'organizationId'],
        'PriceAlertTriggered' => ['PRICE_ALERT', 'NORMAL', 'alertId'],
        'DisputeOpened' => ['DISPUTE_OPENED', 'HIGH', 'disputeId'],
        'DisputeResolved' => ['DISPUTE_RESOLVED', 'NORMAL', 'disputeId'],
        'TierPromoted' => ['TIER_PROMOTED', 'NORMAL', 'organizationId'],
    ];

    /**
     * Properties that name the organisation a notification belongs to, most
     * specific first. A domain event that names none of them is not
     * broadcastable — there is no channel to put it on.
     *
     * @var list<string>
     */
    private const ORGANIZATION_PROPERTIES = [
        'organizationId',
        'cashPayerOrgId',
        'defaultingOrgId',
        'goldDelivererOrgId',
        'payerOrganizationId',
        'actorOrganizationId',
        'ownerOrganizationId',
    ];

    /** @var array<string, bool> event class => Notification listens for it */
    private array $notificationHandles = [];

    public function __construct(private readonly MemberBroadcaster $members) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);
        $basename = $this->basename($event);

        if ($basename === 'NotificationDelivered') {
            $this->fromNotificationModule($shape);

            return;
        }

        if (! isset(self::CURATED[$basename]) || $this->notificationHandles($event::class)) {
            return;
        }

        [$code, $priority, $subjectProperty] = self::CURATED[$basename];

        $organizationId = $shape->firstInt(self::ORGANIZATION_PROPERTIES);

        if ($organizationId === null) {
            return;
        }

        $subjectId = $shape->int($subjectProperty);

        $this->members->notification(
            organizationId: $organizationId,
            id: $code.':'.($subjectId ?? 0),
            code: $code,
            userId: $shape->int('userId'),
            priority: $priority,
            data: array_filter([
                'subject_id' => $subjectId,
                'reason' => $shape->firstString(['reasonCode', 'reason']),
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * A persisted notification row: already deduplicated, already targeted,
     * already in the member's feed under this id. Everything is still read
     * tolerantly — if Notification reshapes its event, the worst case here is a
     * dropped frame, never an exception inside the dispatcher that takes the
     * originating request down with it.
     */
    private function fromNotificationModule(EventShape $shape): void
    {
        $organizationId = $shape->int('organizationId');
        $code = $shape->string('code');

        if ($organizationId === null || $code === null) {
            return;
        }

        $id = $shape->int('notificationId') ?? $shape->int('id');

        $this->members->notification(
            organizationId: $organizationId,
            id: (string) ($id ?? $code),
            code: $code,
            userId: $shape->int('userId'),
            title: $shape->string('subject'),
            body: $shape->string('body'),
            category: $shape->string('category'),
            priority: $shape->string('priority'),
        );
    }

    /**
     * Is the Notification module going to turn this same domain event into a
     * notification row?
     *
     * If it is, the fallback stays quiet and waits for the NotificationDelivered
     * that follows — one alert, one id, the id the member's feed will also show,
     * so a socket frame and a later REST poll collapse into one item. Without
     * this, seven of the ten curated events would arrive twice.
     *
     * Asked of the dispatcher rather than hardcoded, because the answer is
     * exactly "what NotificationServiceProvider registered" and a list copied
     * into this class would start drifting the day someone adds a code there.
     * Notification's listener is named by string: this module sits below it in
     * the dependency graph and may not import it.
     */
    private function notificationHandles(string $eventClass): bool
    {
        if (array_key_exists($eventClass, $this->notificationHandles)) {
            return $this->notificationHandles[$eventClass];
        }

        $handled = false;

        foreach (Event::getRawListeners()[$eventClass] ?? [] as $listener) {
            if (is_string($listener) && str_starts_with($listener, 'App\Modules\Notification\Listeners\\')) {
                $handled = true;

                break;
            }
        }

        return $this->notificationHandles[$eventClass] = $handled;
    }

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
