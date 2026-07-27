<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MarketBroadcaster;
use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * Every order transition → one `order.updated` on the owner's private channel,
 * plus a throttled public `quote.updated` because the book moved.
 *
 * ONE BROADCAST EVENT FOR SIX DOMAIN EVENTS. The client re-renders an order
 * row from `status` and the two quantities; giving it six event names to
 * switch on would only move the mapping into every client, including the
 * Flutter one. The domain event's class basename supplies the status, so the
 * mapping lives in exactly one match arm below.
 *
 * The public quote refresh carries NO order or organisation data — only the
 * instrument. Placing an order is not public information; the fact that the
 * top of book may have moved is.
 */
final class BroadcastOrderLifecycle
{
    /** Domain event basename → the status a client should render. */
    private const STATUS = [
        'OrderPlaced' => 'OPEN',
        'OrderPartiallyFilled' => 'PARTIALLY_FILLED',
        'OrderFilled' => 'FILLED',
        'OrderCancelled' => 'CANCELLED',
        'OrderExpired' => 'EXPIRED',
        'OrderRejected' => 'REJECTED',
    ];

    public function __construct(
        private readonly MarketBroadcaster $market,
        private readonly MemberBroadcaster $members,
    ) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        $basename = $this->basename($event);
        $organizationId = $shape->int('organizationId');

        if ($organizationId === null || ! isset(self::STATUS[$basename])) {
            return;
        }

        // OrderRejected carries a null orderId when nothing was ever written
        // (validation runs outside the transaction), and a rejection the
        // client cannot key to a row is still worth showing.
        $orderId = $shape->int('orderId');

        $this->members->orderUpdated(
            organizationId: $organizationId,
            orderId: $orderId ?? 0,
            status: self::STATUS[$basename],
            orderCode: $shape->string('orderCode'),
            filledMg: $shape->int('filledMg') ?? $shape->int('quantityMg'),
            remainingMg: $shape->int('remainingMg'),
            side: $shape->string('side'),
            lastFill: $this->lastFill($shape),
            reason: $shape->firstString(['reason', 'reasonCode']),
            timestamp: $shape->string('occurredAt'),
        );

        $instrumentId = $shape->int('instrumentId');

        // A rejected order never reached the book, so it moved no price.
        if ($instrumentId !== null && $basename !== 'OrderRejected') {
            $this->market->quote(
                instrumentId: $instrumentId,
                lastPriceRial: $shape->int('lastFillPriceRial'),
                timestamp: $shape->string('occurredAt'),
            );
        }
    }

    /**
     * @return array{quantity_mg: int, price_rial: int}|null
     */
    private function lastFill(EventShape $shape): ?array
    {
        $quantity = $shape->int('filledMg');
        $price = $shape->int('lastFillPriceRial') ?? $shape->int('averagePriceRial');

        if ($quantity === null || $price === null) {
            return null;
        }

        return ['quantity_mg' => $quantity, 'price_rial' => $price];
    }

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
