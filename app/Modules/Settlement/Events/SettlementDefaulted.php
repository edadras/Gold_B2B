<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * 24 hours past the deadline with nothing paid (§5.5, T+24h).
 *
 * Downstream: Reputation drops the credit score by 250, Risk raises the member
 * to HIGH, and a dispute is opened automatically. Collateral seizure is NOT
 * triggered by this event — §5.8 and worked example 6 scenario ب require dual
 * approval by a SETTLEMENT_OFFICER and a PLATFORM_ADMIN first.
 */
final readonly class SettlementDefaulted
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public int $defaultingOrgId,
        public int $injuredOrgId,
        public int $amountRial,
        public int $fineWeightMg,
        public int $penaltyRial,
        public string $overdueSince,
        public int $hoursOverdue,
        public string $occurredAt,
    ) {}
}
