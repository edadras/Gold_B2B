<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * Live notification delivery on `private-org.{orgId}.notification` (§3.2).
 *
 * TWO INPUTS, ONE OUTPUT.
 *
 * 1. `App\Modules\Notification\Events\NotificationDelivered`, by string name.
 *    That class does not exist yet — the Notification module currently writes
 *    its rows and returns a DispatchResult without emitting an event — but
 *    registering the listener now costs nothing (Laravel never fires a name
 *    nobody dispatches) and means the socket lights up the moment Notification
 *    starts announcing itself, with no change here. Broadcasting could not
 *    have added that event anyway: Notification is another module's code.
 *
 * 2. A curated list of domain events that a member must be told about even
 *    when nobody is looking at the blotter — an overdue settlement, a rejected
 *    order, an assay variance. This is what actually feeds the bell icon today.
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

    public function __construct(private readonly MemberBroadcaster $members) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);
        $basename = $this->basename($event);

        if ($basename === 'NotificationDelivered') {
            $this->fromNotificationModule($shape);

            return;
        }

        if (! isset(self::CURATED[$basename])) {
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
     * The shape Notification will emit when it grows an event: a persisted
     * notification row, already deduplicated and already respecting quiet
     * hours and preferences. Everything is read tolerantly so that whatever
     * that class ends up looking like, the worst case is a dropped frame.
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

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
