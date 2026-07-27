<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * A rial obligation has been discharged with gold (or the reverse) at a rate
 * both members agreed — docs/03-domain/05-settlement.md §5.7.
 *
 * Carries the rate as executed, not as quoted: an auditor reconstructing why
 * 63.694 g extinguished a five-billion-rial debt needs the number the parties
 * signed up to, and the rounding remainder that the platform absorbed to make
 * the two sides meet exactly.
 */
final readonly class CrossSettlementExecuted
{
    public function __construct(
        public int $crossSettlementId,
        public string $crossCode,
        public int $rialSettlementId,
        public int $goldSettlementId,
        public int $debtorOrgId,
        public int $creditorOrgId,
        public int $agreedRateRial,
        public int $rialObligationRial,
        public int $goldObligationMg,
        public int $goldAppliedMg,
        public int $rialDischargedRial,
        public int $goldValueRial,
        public int $roundingRial,
        public int $goldRemainingMg,
        public int $rialRemainingRial,
        public ?string $transactionGroup,
        public string $occurredAt,
    ) {}
}
