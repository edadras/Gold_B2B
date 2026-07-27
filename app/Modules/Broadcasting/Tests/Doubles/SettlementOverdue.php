<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * Stand-in for App\Modules\Settlement\Events\SettlementOverdue.
 *
 * A double rather than the real class for the reason given in SettlementOpened:
 * Broadcasting may depend only on Shared and Identity, and the architecture
 * suite enforces that over test files too.
 *
 * This one is in BroadcastNotification's curated list *and* handled by the
 * Notification module, which makes it the event the fallback rule is about.
 */
final readonly class SettlementOverdue
{
    public function __construct(
        public int $organizationId,
        public int $settlementId,
        public string $occurredAt = '',
    ) {}
}
