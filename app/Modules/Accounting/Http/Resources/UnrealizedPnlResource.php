<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Resources;

use App\Modules\Accounting\Contracts\UnrealizedPnl;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /accounting/unrealized-pnl` — F17.
 *
 * INFORMATIONAL ONLY, and the payload says so in a field rather than only in a
 * comment. Nothing here has been realised, so nothing here is in the journal;
 * §9.4 prints it under «اطلاعاتی (غیر از دفتر)» for that reason.
 *
 * `available: false` is not the same as a zero gain. When no market price can
 * be read the amounts are meaningless and the client must show "unknown", not
 * "۰ ریال" — so the flag is checked before the numbers, and the `_display`
 * companions are suppressed when it is false.
 *
 * The documentation's §2.13 table does not list this endpoint; it is the
 * unrealised half of `/reports/pnl`, exposed separately because Reporting's PnL
 * statement must never contain it.
 *
 * @mixin UnrealizedPnl
 */
final class UnrealizedPnlResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var UnrealizedPnl $pnl */
        $pnl = $this->resource;

        return [
            'available' => $pnl->available,
            'informational_only' => true,
            'quantity_mg' => $pnl->quantityMg,
            'book_value_rial' => $pnl->bookValueRial,
            'market_value_rial' => $pnl->marketValueRial,
            'unrealized_rial' => $pnl->unrealizedRial,
            'market_price_per_gram' => $pnl->marketPricePerGram,
            'is_gain' => $pnl->isGain(),
            'note' => 'این ارقام تحقق‌نیافته‌اند و در دفتر روزنامه ثبت نمی‌شوند.',
        ] + $this->display($request, [
            'quantity_display' => Display::grams($pnl->quantityMg),
            'book_value_display' => Display::rial($pnl->bookValueRial),
            'market_value_display' => $pnl->available ? Display::rial($pnl->marketValueRial) : null,
            'unrealized_display' => $pnl->available ? Display::rial($pnl->unrealizedRial) : null,
            'market_price_display' => $pnl->marketPricePerGram === null
                ? null
                : Display::rial($pnl->marketPricePerGram),
        ]);
    }
}
