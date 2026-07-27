<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * The 24-hour objection window closed without complaint and the settlement is
 * final (§5.2: SETTLED → COMPLETED).
 *
 * Final in the ordinary sense only: appendix §2.14 rule 3 still allows DISPUTED
 * and REVERSED afterwards, because fraud found next week must be correctable.
 */
final readonly class SettlementCompleted
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public int $tradeId,
        public int $goldDelivererOrgId,
        public int $goldReceiverOrgId,
        public int $fineWeightMg,
        public int $cashAmountRial,
        public string $settledAt,
        public string $occurredAt,
    ) {}
}
