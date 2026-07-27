<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * The deadline passed (§5.5, T+0h).
 *
 * escalationLevel tracks how far up the §5.5 ladder this settlement has
 * climbed: 1 = overdue, 2 = penalty accruing (T+2h), 3 = operator alerted and
 * new orders suspended (T+6h). The event is re-fired at each step so
 * Notification and Risk can react without polling.
 */
final readonly class SettlementOverdue
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public int $cashPayerOrgId,
        public int $cashReceiverOrgId,
        public int $amountRial,
        public string $deadlineAt,
        public string $overdueSince,
        public int $hoursOverdue,
        public int $escalationLevel,
        public int $penaltyRial,
        public bool $suspendNewOrders,
        public string $occurredAt,
    ) {}
}
