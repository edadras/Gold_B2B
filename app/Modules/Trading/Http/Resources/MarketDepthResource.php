<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Contracts\OrderBookLevel;
use App\Modules\Trading\Contracts\OrderBookSnapshot;
use Illuminate\Http\Request;

/**
 * `GET /market/depth/{code}` — docs/05-api/02-endpoints.md §2.4.
 *
 * DISCLOSURE RULE, enforced by construction: a level carries a price, an
 * aggregate quantity and a COUNT of orders. It never carries an owner, an order
 * id, or the size of any individual order — "هویت سفارش‌دهنده هرگز در عمق بازار
 * نمایش داده نمی‌شود" (docs/03-domain/04-trading.md §4.9).
 *
 * This resource can only build what OrderBookSnapshot holds, and
 * OrderBookReader never selects an owner column in the first place, so there
 * are two independent reasons no identity can appear here.
 */
final class MarketDepthResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OrderBookSnapshot $depth */
        $depth = $this->resource;

        return [
            'instrument' => $depth->instrumentCode,
            'bids' => array_map($this->level(...), $depth->bids),
            'asks' => array_map($this->level(...), $depth->asks),
            'spread_rial' => $depth->spread(),
            'mid_price_rial' => $depth->midPrice(),
            'as_of' => $depth->timestamp,
        ] + $this->display($request, [
            'spread_display' => Display::rial($depth->spread()),
            'mid_price_display' => Display::rial($depth->midPrice()),
        ]);
    }

    /** @return array<string, int> */
    private function level(OrderBookLevel $level): array
    {
        return [
            'price_rial' => $level->priceRial,
            'quantity_mg' => $level->quantityMg,
            'order_count' => $level->orderCount,
        ];
    }
}
