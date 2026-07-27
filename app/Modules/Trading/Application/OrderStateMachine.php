<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderStatusEvent;

/**
 * The only thing in this module allowed to change orders.status.
 *
 * Rules 1 and 2 of docs/11-appendix/02-state-machines.md §2.14: no transition
 * happens without consulting allowedTransitions(), and every transition that
 * does happen is written to the event log with who, when and why.
 *
 * Unlike the §2.1 sketch this does NOT open its own transaction — every caller
 * is already inside the transaction that owns the financial side of the change,
 * and nesting would let the status commit while the money rolled back.
 */
final class OrderStateMachine
{
    /** @param array<string, mixed>|null $metadata */
    public function transition(
        Order $order,
        OrderStatus $target,
        string $actorType = OrderStatusEvent::ACTOR_SYSTEM,
        ?int $actorUserId = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $metadata = null,
    ): void {
        $current = $order->status;

        if ($current === $target) {
            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException('Order', $current->value, $target->value);
        }

        $order->status = $target;

        if ($target->isFinal()) {
            $order->closed_at = now();
        }

        if ($target === OrderStatus::REJECTED && $reason !== null) {
            $order->reject_reason = $reason;
        }

        $order->save();

        OrderStatusEvent::create([
            'order_id' => $order->id,
            'from_status' => $current->value,
            'to_status' => $target->value,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Move an order to whichever fill status its quantities now imply.
     *
     * Called after every execution: nothing filled leaves the status alone,
     * a partial fill lands in PARTIALLY_FILLED, a complete one in FILLED.
     */
    public function reconcileFillStatus(Order $order, ?int $tradeId = null): void
    {
        $target = match (true) {
            $order->filled_mg >= $order->quantity_mg => OrderStatus::FILLED,
            $order->filled_mg > 0 => OrderStatus::PARTIALLY_FILLED,
            default => null,
        };

        if ($target === null || $order->status === $target) {
            return;
        }

        $this->transition(
            order: $order,
            target: $target,
            reason: 'execution',
            referenceType: $tradeId === null ? null : 'trade',
            referenceId: $tradeId,
        );
    }

    /** Records the initial PENDING -> OPEN with its own log row. */
    public function open(Order $order, int $actorUserId): void
    {
        $this->transition(
            order: $order,
            target: OrderStatus::OPEN,
            actorType: OrderStatusEvent::ACTOR_USER,
            actorUserId: $actorUserId,
            reason: 'reservation accepted',
        );
    }
}
