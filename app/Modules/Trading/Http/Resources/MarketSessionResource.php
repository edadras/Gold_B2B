<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Infrastructure\Models\MarketSession;
use Illuminate\Http\Request;

/**
 * `GET /market/sessions/{code}` — is the market open, and what has it done today.
 *
 * @mixin MarketSession
 */
final class MarketSessionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MarketSession $session */
        $session = $this->resource;

        return [
            'session_date' => $session->session_date?->toDateString(),
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'pre_open_at' => Display::iso($session->pre_open_at),
            'opens_at' => Display::iso($session->opens_at),
            'closes_at' => Display::iso($session->closes_at),
            'opened_at' => Display::iso($session->opened_at),
            'paused_at' => Display::iso($session->paused_at),
            'resume_at' => Display::iso($session->resume_at),
            'closed_at' => Display::iso($session->closed_at),
            'opening_price_rial' => $session->opening_price_rial === null ? null : (int) $session->opening_price_rial,
            'closing_price_rial' => $session->closing_price_rial === null ? null : (int) $session->closing_price_rial,
            'high_price_rial' => $session->high_price_rial === null ? null : (int) $session->high_price_rial,
            'low_price_rial' => $session->low_price_rial === null ? null : (int) $session->low_price_rial,
            'volume_mg' => (int) $session->volume_mg,
            'trade_count' => (int) $session->trade_count,
        ] + $this->display($request, [
            'volume_display' => Display::grams((int) $session->volume_mg),
            'session_date_jalali' => Display::jalali($session->session_date, withTime: false),
        ]);
    }
}
