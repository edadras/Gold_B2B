<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * A settled settlement was reversed under dual control (§5.8).
 *
 * The original settlement and trade rows are untouched; the ledger carries a
 * mirror-image transaction_group. requestedByUserId and approvedByUserId are
 * always two different people.
 */
final readonly class SettlementReversed
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public string $fromStatus,
        public int $goldDelivererOrgId,
        public int $goldReceiverOrgId,
        public int $fineWeightMg,
        public int $cashAmountRial,
        public string $reason,
        public int $requestedByUserId,
        public int $approvedByUserId,
        public ?string $transactionGroup,
        public bool $lotsReturned,
        public string $occurredAt,
    ) {}
}
