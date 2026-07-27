<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Listeners;

use App\Modules\Counterparty\Application\RelationService;

/**
 * Keeps the relationship quality counters (`overdue_count`, `dispute_count`)
 * that §10.3 shows on the statement header.
 *
 * Subscribed by string name to events owned by Settlement and Dispute; the
 * handler branches on the event's own class name rather than on its type, since
 * those classes may not exist in this deployment slice at all.
 */
final class RecordRelationshipIncident
{
    private const OVERDUE_EVENTS = [
        'App\Modules\Settlement\Events\SettlementOverdue',
        'App\Modules\Settlement\Events\SettlementDefaulted',
    ];

    private const DISPUTE_EVENTS = [
        'App\Modules\Dispute\Events\DisputeOpened',
    ];

    public function __construct(private readonly RelationService $relations) {}

    public function handle(object $event): void
    {
        $pair = $this->pair($event);

        if ($pair === null) {
            return;
        }

        [$left, $right] = $pair;
        $class = $event::class;

        if (in_array($class, self::OVERDUE_EVENTS, true)) {
            // Both sides of the pair record the incident: the late side's
            // reliability drops for the counterparty, and the injured side needs
            // the count on its own view of the relationship.
            $this->relations->recordOverdue($left, $right);
            $this->relations->recordOverdue($right, $left);

            return;
        }

        if (in_array($class, self::DISPUTE_EVENTS, true)) {
            $this->relations->recordDispute($left, $right);
            $this->relations->recordDispute($right, $left);
        }
    }

    /** @return array{0: int, 1: int}|null */
    private function pair(object $event): ?array
    {
        foreach ([['initiatorOrgId', 'respondentOrgId'], ['buyerOrgId', 'sellerOrgId'], ['organizationId', 'counterpartyOrgId']] as [$a, $b]) {
            if (property_exists($event, $a) && property_exists($event, $b)) {
                $left = (int) $event->{$a};
                $right = (int) $event->{$b};

                return $left !== $right && $left > 0 && $right > 0 ? [$left, $right] : null;
            }
        }

        return null;
    }
}
