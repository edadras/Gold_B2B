<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderFill;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * One of the caller's own orders, shaped like the sample in
 * docs/05-api/02-endpoints.md §2.5.
 *
 * `instrument` is the CODE, not the id: the id is an internal surrogate and the
 * client already keys its instrument cache on the code.
 *
 * `fills` carry the trade code, size, price and time — never the counterparty.
 * Even on your own order, who filled you is not disclosed (§4.9).
 *
 * @mixin Order
 */
final class OrderResource extends ApiResource
{
    /** @param Collection<int, OrderFill>|null $fills */
    public function __construct(
        mixed $resource,
        private readonly ?string $instrumentCode = null,
        private readonly ?Collection $fills = null,
        private readonly array $tradeCodes = [],
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $payload = [
            'id' => (int) $order->id,
            'order_code' => (string) $order->order_code,
            'instrument' => $this->instrumentCode,
            'instrument_id' => (int) $order->instrument_id,
            'side' => $order->side->value,
            'type' => $order->order_type->value,
            'time_in_force' => $order->time_in_force->value,
            'quantity_mg' => (int) $order->quantity_mg,
            'filled_mg' => (int) $order->filled_mg,
            'remaining_mg' => $order->remainingMg(),
            'price_rial' => $order->price_rial === null ? null : (int) $order->price_rial,
            'max_slippage_bps' => $order->max_slippage_bps === null ? null : (int) $order->max_slippage_bps,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'reserved_amount' => (int) $order->reserved_amount,
            'consumed_amount' => (int) $order->consumed_amount,
            'released_amount' => (int) $order->released_amount,
            'outstanding_reservation' => $order->outstandingReservation(),
            'placed_at' => Display::iso($order->placed_at),
            'expires_at' => Display::iso($order->expires_at),
        ];

        if ($this->fills !== null) {
            $payload['fills'] = $this->fills->map(fn (OrderFill $fill): array => [
                'trade_code' => $this->tradeCodes[(int) $fill->trade_id] ?? null,
                'role' => (string) $fill->role,
                'quantity_mg' => (int) $fill->quantity_mg,
                'price_rial' => (int) $fill->price_rial,
                'gross_amount_rial' => (int) $fill->gross_amount_rial,
                'fee_rial' => (int) $fill->fee_rial,
                'executed_at' => Display::iso($fill->filled_at ?? $fill->created_at),
            ])->all();
        }

        return $payload + $this->display($request, [
            'quantity_display' => Display::grams((int) $order->quantity_mg),
            'filled_display' => Display::grams((int) $order->filled_mg),
            'remaining_display' => Display::grams($order->remainingMg()),
            'price_display' => Display::rial($order->price_rial === null ? null : (int) $order->price_rial),
            'placed_at_jalali' => Display::jalali($order->placed_at),
            'expires_at_jalali' => Display::jalali($order->expires_at),
        ]);
    }
}
