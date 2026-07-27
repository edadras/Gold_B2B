<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application\Commands;

use App\Modules\Risk\Contracts\TradeIntent;
use App\Modules\Risk\Contracts\TradeSide;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything needed to place one order, validated at construction.
 *
 * Shape follows docs/06-backend-laravel/02-implementation-guide.md §2.2. The
 * quantity is a FineWeight and the price a PricePerFineGram, so a gross weight
 * or a rial total cannot reach the engine by accident.
 */
final readonly class PlaceOrderCommand
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $organizationId,
        public int $userId,
        public ?int $representativeId,
        public string $instrumentCode,
        public Side $side,
        public OrderType $type,
        public TimeInForce $timeInForce,
        public FineWeight $quantity,
        public ?PricePerFineGram $price,
        public ?int $maxSlippageBps = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $metadata = [],
    ) {
        if ($type === OrderType::LIMIT && $price === null) {
            throw new InvalidArgumentException('A LIMIT order requires a price');
        }

        if ($timeInForce === TimeInForce::GTD && $expiresAt === null) {
            throw new InvalidArgumentException('A GTD order requires an expiry');
        }

        if ($quantity->isZero()) {
            throw new InvalidArgumentException('Order quantity must be positive');
        }
    }

    /**
     * The pre-trade gate's view of this order.
     *
     * $referencePrice stands in for a market order's unknown price, so a
     * notional value can still be checked against the member's limits.
     */
    public function toIntent(PricePerFineGram $referencePrice, string $settlementType): TradeIntent
    {
        return TradeIntent::make(
            organizationId: $this->organizationId,
            userId: $this->userId,
            side: $this->side->isBuy() ? TradeSide::BUY : TradeSide::SELL,
            fineWeight: $this->quantity,
            price: $this->price ?? $referencePrice,
            settlementType: $settlementType,
        );
    }
}
