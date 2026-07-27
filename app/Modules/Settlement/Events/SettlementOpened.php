<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * A trade produced a settlement and both sides' assets moved from RESERVED to
 * IN_SETTLEMENT (docs/03-domain/05-settlement.md §5.2, CREATED → ASSETS_LOCKED).
 *
 * Fired after commit. Scalars only, so a listener can be queued without
 * dragging a model through serialisation.
 */
final readonly class SettlementOpened
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public int $tradeId,
        public int $goldDelivererOrgId,
        public int $goldReceiverOrgId,
        public int $cashPayerOrgId,
        public int $cashReceiverOrgId,
        public int $fineWeightMg,
        public int $cashAmountRial,
        public int $buyerFeeRial,
        public int $sellerFeeRial,
        public string $settlementType,
        public string $deadlineAt,
        public string $occurredAt,
    ) {}
}
