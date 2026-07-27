<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

final readonly class SettlementRow
{
    public function __construct(
        public int $id,
        public string $settlementCode,
        public string $status,
        public string $settlementType,
        public ?int $tradeId,
        public int $goldDelivererOrgId,
        public int $goldReceiverOrgId,
        public ?string $goldDelivererName,
        public ?string $goldReceiverName,
        public int $fineWeightMg,
        public int $cashAmountRial,
        public ?string $deadlineAt,
        public ?string $overdueSince,
        public int $penaltyRial,
        public int $escalationLevel,
        public ?int $disputeId,
    ) {}
}
