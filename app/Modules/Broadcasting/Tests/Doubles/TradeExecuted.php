<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles;

/**
 * Stand-in for App\Modules\Trading\Events\TradeExecuted.
 *
 * WHY A DOUBLE AND NOT THE REAL CLASS. Broadcasting may depend only on Shared
 * and Identity, and tests/Architecture/ArchitectureTest.php enforces that over
 * every file in the module — test files included. Importing the real event here
 * would make the architecture suite fail, which is exactly right: this module
 * is built to know these events only by name and shape, so its tests are built
 * the same way. The class BASENAME matches the real one because the listeners
 * dispatch on it.
 */
final readonly class TradeExecuted
{
    public function __construct(
        public int $tradeId,
        public string $tradeCode,
        public int $instrumentId,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $fineWeightMg,
        public int $pricePerGramRial,
        public int $grossAmountRial,
        public int $buyerFeeRial,
        public int $sellerFeeRial,
        public int $buyerNetRial,
        public int $sellerNetRial,
        public ?string $makerSide = null,
        public ?string $settlementCode = null,
        public ?string $settlementDeadline = null,
        public string $executedAt = '',
    ) {}
}
