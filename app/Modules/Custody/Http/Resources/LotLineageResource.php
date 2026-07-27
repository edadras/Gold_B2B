<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /lots/{id}/lineage` — the shape sampled in
 * docs/05-api/02-endpoints.md §2.10: `lot`, `ancestors`, `descendants`,
 * `operations`.
 *
 * The array comes ready-assembled from LineageViewService; this class only
 * adds the `_display` companions, which are per-request (they are dropped by
 * `?include_display=false`) and therefore cannot be baked in upstream.
 */
final class LotLineageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{lot: array<string, mixed>, ancestors: list<array<string, mixed>>, descendants: list<array<string, mixed>>, operations: list<array<string, mixed>>} $view */
        $view = $this->resource;

        return [
            'lot' => $view['lot'],
            'ancestors' => array_map(fn (array $hop): array => $this->hop($request, $hop), $view['ancestors']),
            'descendants' => array_map(fn (array $hop): array => $this->hop($request, $hop), $view['descendants']),
            'operations' => array_map(fn (array $op): array => $this->operation($request, $op), $view['operations']),
        ];
    }

    /**
     * @param  array<string, mixed>  $hop
     * @return array<string, mixed>
     */
    private function hop(Request $request, array $hop): array
    {
        return $hop + $this->display($request, [
            'gross_weight_display' => Display::grams(
                is_int($hop['gross_weight_mg'] ?? null) ? $hop['gross_weight_mg'] : null,
            ),
            'fine_weight_display' => Display::grams(
                is_int($hop['fine_weight_mg'] ?? null) ? $hop['fine_weight_mg'] : null,
            ),
            'purity_display' => Display::purity(
                is_int($hop['purity_x10'] ?? null) ? $hop['purity_x10'] : null,
            ),
            'occurred_at_jalali' => Display::jalali(
                is_string($hop['occurred_at'] ?? null) ? $hop['occurred_at'] : null,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operation(Request $request, array $operation): array
    {
        return $operation + $this->display($request, [
            'input_fine_display' => Display::grams(
                is_int($operation['input_fine_mg'] ?? null) ? $operation['input_fine_mg'] : null,
            ),
            'output_fine_display' => Display::grams(
                is_int($operation['output_fine_mg'] ?? null) ? $operation['output_fine_mg'] : null,
            ),
            'loss_fine_display' => Display::grams(
                is_int($operation['loss_fine_mg'] ?? null) ? $operation['loss_fine_mg'] : null,
            ),
            'occurred_at_jalali' => Display::jalali(
                is_string($operation['occurred_at'] ?? null) ? $operation['occurred_at'] : null,
            ),
        ]);
    }
}
