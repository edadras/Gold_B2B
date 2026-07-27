<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Trading\Domain\Exceptions\InvalidOrderException;
use App\Modules\Trading\Domain\Exceptions\TradingEntityNotFoundException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Events\OrderCancelled;
use App\Modules\Trading\Events\OrderExpired;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderStatusEvent;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling an order releases the part of its reservation that no fill ever
 * claimed — the "* → CANCELLED: رزرو باقیمانده آزاد می‌شود" side effect of
 * docs/11-appendix/02-state-machines.md §2.3.
 *
 * What stays locked is `consumed_amount`: gold or rial already committed to an
 * executed trade, which Settlement will move RESERVED → IN_SETTLEMENT. Only the
 * genuinely unfilled remainder comes back.
 */
final readonly class CancelOrderService
{
    public function __construct(
        private GoldLedgerInterface $goldLedger,
        private RialLedgerInterface $rialLedger,
        private OrderStateMachine $stateMachine,
    ) {}

    /**
     * Member-initiated cancellation.
     *
     * @throws InvalidOrderException when the order is already final
     */
    public function cancel(int $orderId, int $organizationId, int $userId, string $reason = 'cancelled by member'): Order
    {
        $result = DB::transaction(function () use ($orderId, $organizationId, $userId, $reason): array {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->organization_id !== $organizationId) {
                throw TradingEntityNotFoundException::order($orderId);
            }

            if ($order->status->isFinal()) {
                throw InvalidOrderException::notCancellable($orderId, $order->status->value);
            }

            $released = $this->releaseOutstanding($order);

            $this->stateMachine->transition(
                order: $order,
                target: OrderStatus::CANCELLED,
                actorType: OrderStatusEvent::ACTOR_USER,
                actorUserId: $userId,
                reason: $reason,
            );

            return [$order, $released];
        }, 3);

        [$order, $released] = $result;

        event(new OrderCancelled(
            orderId: $order->id,
            organizationId: $order->organization_id,
            instrumentId: $order->instrument_id,
            side: $order->side->value,
            filledMg: $order->filled_mg,
            cancelledMg: $order->remainingMg(),
            releasedAmount: $released,
            reason: $reason,
            occurredAt: now()->toIso8601String(),
        ));

        return $order;
    }

    /**
     * System-initiated cancellation: IOC remainder, session close, suspension.
     *
     * MUST be called inside a transaction that already holds the order row; it
     * neither opens one nor fires an event, so the caller controls both.
     */
    public function cancelWithinTransaction(Order $order, string $reason, ?int $actorUserId = null): int
    {
        $released = $this->releaseOutstanding($order);

        $this->stateMachine->transition(
            order: $order,
            target: OrderStatus::CANCELLED,
            actorType: $actorUserId === null ? OrderStatusEvent::ACTOR_SYSTEM : OrderStatusEvent::ACTOR_USER,
            actorUserId: $actorUserId,
            reason: $reason,
        );

        return $released;
    }

    /** As above, but the terminal state is EXPIRED. */
    public function expireWithinTransaction(Order $order, string $reason): int
    {
        $released = $this->releaseOutstanding($order);

        $this->stateMachine->transition(
            order: $order,
            target: OrderStatus::EXPIRED,
            reason: $reason,
        );

        return $released;
    }

    /** Fired by the caller after commit — kept here so the payload stays in one place. */
    public static function cancelledEvent(Order $order, int $released, string $reason): OrderCancelled
    {
        return new OrderCancelled(
            orderId: $order->id,
            organizationId: $order->organization_id,
            instrumentId: $order->instrument_id,
            side: $order->side->value,
            filledMg: $order->filled_mg,
            cancelledMg: $order->remainingMg(),
            releasedAmount: $released,
            reason: $reason,
            occurredAt: now()->toIso8601String(),
        );
    }

    public static function expiredEvent(Order $order, int $released): OrderExpired
    {
        return new OrderExpired(
            orderId: $order->id,
            organizationId: $order->organization_id,
            instrumentId: $order->instrument_id,
            filledMg: $order->filled_mg,
            expiredMg: $order->remainingMg(),
            releasedAmount: $released,
            occurredAt: now()->toIso8601String(),
        );
    }

    /**
     * Hand back everything still locked and not claimed by a fill.
     *
     * @return int the amount released, in rial for a BUY and milligrams for a SELL
     */
    private function releaseOutstanding(Order $order): int
    {
        $outstanding = $order->outstandingReservation();

        if ($outstanding <= 0 || $order->reservation_entry_id === null) {
            return 0;
        }

        $reservationId = LedgerEntryId::fromInt($order->reservation_entry_id);

        if ($order->side->isBuy()) {
            $this->rialLedger->release($reservationId, Rial::fromRial($outstanding));
        } else {
            $this->goldLedger->release($reservationId, FineWeight::fromMilligrams($outstanding));
        }

        $order->released_amount += $outstanding;
        $order->save();

        return $outstanding;
    }
}
