<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /balances` — shaped exactly like the sample in
 * docs/05-api/02-endpoints.md §2.3.
 *
 * Every figure is an integer: milligrams for gold, rial for money. The
 * `_display` strings are convenience only and nothing computes on them (§1.12).
 */
final class BalanceSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{gold: array<string, mixed>, rial: array<string, mixed>, as_of: string} $summary */
        $summary = $this->resource;

        return [
            'gold' => $summary['gold'] + $this->display($request, [
                'available_display' => Display::grams((int) $summary['gold']['available_mg']),
                'reserved_display' => Display::grams((int) $summary['gold']['reserved_mg']),
                'total_display' => Display::grams((int) $summary['gold']['total_mg']),
                'market_value_display' => Display::rial(
                    $summary['gold']['market_value_rial'] === null
                        ? null
                        : (int) $summary['gold']['market_value_rial']
                ),
            ]),
            'rial' => $summary['rial'] + $this->display($request, [
                'available_display' => Display::rial((int) $summary['rial']['available']),
                'net_display' => Display::rial((int) $summary['rial']['net']),
            ]),
            'as_of' => $summary['as_of'],
        ] + $this->display($request, [
            'as_of_jalali' => Display::jalali($summary['as_of']),
        ]);
    }
}
