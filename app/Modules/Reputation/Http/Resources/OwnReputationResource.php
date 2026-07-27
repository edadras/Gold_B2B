<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reputation/me` — the member's own statistics.
 *
 * §14.9 says a member "must see exactly its own statistics" and be able to
 * challenge them, so this payload is richer than the public one: it carries the
 * numerators and denominators behind every rate, which is what makes the
 * arithmetic checkable. That extra detail is precisely why this endpoint IS
 * tenant-scoped while `/members/{id}/reputation` is not.
 *
 * The array comes from `PublicProfileService::ownStatistics()` already
 * assembled; this resource adds only display companions, which §1.12 allows and
 * `?include_display=false` removes.
 */
final class OwnReputationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $statistics */
        $statistics = $this->resource;

        $volumeMg = isset($statistics['total_volume_mg']) ? (int) $statistics['total_volume_mg'] : null;
        $makerMg = isset($statistics['maker_volume_mg']) ? (int) $statistics['maker_volume_mg'] : null;
        $takerMg = isset($statistics['taker_volume_mg']) ? (int) $statistics['taker_volume_mg'] : null;

        return $statistics + $this->display($request, [
            'total_volume_display' => Display::grams($volumeMg),
            'maker_volume_display' => Display::grams($makerMg),
            'taker_volume_display' => Display::grams($takerMg),
            'on_time_settlement_rate_display' => Display::bps(
                isset($statistics['on_time_settlement_rate_bps'])
                    ? (int) $statistics['on_time_settlement_rate_bps']
                    : null
            ),
            'dispute_rate_display' => Display::bps(
                isset($statistics['dispute_rate_bps']) ? (int) $statistics['dispute_rate_bps'] : null
            ),
            'maker_share_display' => Display::bps(
                isset($statistics['maker_share_bps']) ? (int) $statistics['maker_share_bps'] : null
            ),
        ]);
    }
}
