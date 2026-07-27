<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * The settlement was called off by agreement or by an operator (§5.2:
 * any pre-SETTLED state → CANCELLED, "آزادسازی قفل‌ها").
 *
 * Whatever was held in IN_SETTLEMENT has already gone back to AVAILABLE by the
 * time this fires.
 */
final readonly class SettlementCancelled
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public string $fromStatus,
        public int $goldDelivererOrgId,
        public int $cashPayerOrgId,
        public int $releasedGoldMg,
        public int $releasedCashRial,
        public string $reason,
        public ?int $actorUserId,
        public string $occurredAt,
    ) {}
}
