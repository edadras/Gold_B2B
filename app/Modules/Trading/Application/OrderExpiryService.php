<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\TimeInForce;
use App\Modules\Trading\Events\OrderCancelled;
use App\Modules\Trading\Events\OrderExpired;
use App\Modules\Trading\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The two time-driven ways an order leaves the book (§4.2, §4.8):
 *
 *   GTD  — expires_at passed                          -> EXPIRED
 *   DAY  — its session closed                         -> CANCELLED
 *   GTC  — neither; it survives the close by design.
 *
 * Each order is handled in its own short transaction rather than one long one:
 * a single stuck row must not hold the whole book's locks, and the events are
 * collected and fired only after every commit (AGENT_BRIEF rule 3).
 */
final readonly class OrderExpiryService
{
    public function __construct(private CancelOrderService $canceller) {}

    /**
     * Expire every GTD order whose deadline has passed.
     *
     * @return int how many were expired
     */
    public function expireDueOrders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = Order::query()
            ->whereIn('status', OrderStatus::matchable())
            ->where('time_in_force', TimeInForce::GTD->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        /** @var list<OrderExpired> $events */
        $events = [];

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?OrderExpired {
                $order = Order::query()->lockForUpdate()->find($id);

                if ($order === null || ! $order->status->isActive()) {
                    return null;
                }

                $released = $this->canceller->expireWithinTransaction($order, 'time in force expired');

                return CancelOrderService::expiredEvent($order, $released);
            }, 3);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        foreach ($events as $event) {
            event($event);
        }

        return count($events);
    }

    /**
     * Cancel the DAY orders of one instrument at the close. GTC and GTD orders
     * are left alone — "سفارش‌های DAY لغو می‌شوند، GTC باقی می‌مانند" (§4.8).
     *
     * @return int how many were cancelled
     */
    public function cancelDayOrders(int $instrumentId): int
    {
        $ids = Order::query()
            ->where('instrument_id', $instrumentId)
            ->whereIn('status', OrderStatus::matchable())
            ->where('time_in_force', TimeInForce::DAY->value)
            ->orderBy('id')
            ->pluck('id');

        /** @var list<OrderCancelled> $events */
        $events = [];

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?OrderCancelled {
                $order = Order::query()->lockForUpdate()->find($id);

                if ($order === null || ! $order->status->isActive()) {
                    return null;
                }

                $released = $this->canceller->cancelWithinTransaction($order, 'session closed');

                return CancelOrderService::cancelledEvent($order, $released, 'session closed');
            }, 3);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        foreach ($events as $event) {
            event($event);
        }

        return count($events);
    }

    /**
     * Cancel everything an organisation has resting, in any instrument. Used by
     * the suspension listener.
     *
     * @return int how many were cancelled
     */
    public function cancelAllForOrganization(int $organizationId, string $reason): int
    {
        $ids = Order::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', OrderStatus::matchable())
            ->orderBy('id')
            ->pluck('id');

        /** @var list<OrderCancelled> $events */
        $events = [];

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id, $reason): ?OrderCancelled {
                $order = Order::query()->lockForUpdate()->find($id);

                if ($order === null || ! $order->status->isActive()) {
                    return null;
                }

                $released = $this->canceller->cancelWithinTransaction($order, $reason);

                return CancelOrderService::cancelledEvent($order, $released, $reason);
            }, 3);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        foreach ($events as $event) {
            event($event);
        }

        return count($events);
    }
}
