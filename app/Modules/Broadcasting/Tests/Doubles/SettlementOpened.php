<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * Stand-in for App\Modules\Settlement\Events\SettlementOpened.
 *
 * WHY A DOUBLE AND NOT THE REAL CLASS. Broadcasting may depend only on Shared
 * and Identity, and tests/Architecture/ArchitectureTest.php enforces that over
 * every file in the module — test files included. Importing the real event here
 * would make the architecture suite fail, which is exactly right: this module
 * is built to know these events only by name and shape, so its tests are built
 * the same way. The class BASENAME matches the real one because the listeners
 * dispatch on it.
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
        public string $occurredAt = '',
    ) {}
}
