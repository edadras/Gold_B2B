<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Infrastructure\Models\LimitIncreaseRequest;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `POST /risk/limit-increase-request` (§2.15).
 *
 * `failed_prerequisites` is returned in full: §11.8's screen can only reject,
 * never approve, and a member told "rejected" without being told which of the
 * six checks failed cannot do anything about it.
 *
 * `prerequisite_snapshot` and `decision_notes` are withheld — the snapshot is
 * the compliance officer's evidence trail and the notes are their internal
 * reasoning.
 *
 * Values are unitless integers here because the unit depends on `limit_type`:
 * milligrams for the weight-based types, rial for OPEN_EXPOSURE_RIAL, a count
 * for OPEN_ORDERS. `value_unit` says which, so no client has to guess.
 *
 * @mixin LimitIncreaseRequest
 */
final class LimitIncreaseRequestResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LimitIncreaseRequest $increase */
        $increase = $this->resource;

        $unit = $this->unitOf($increase);

        return [
            'id' => (int) $increase->id,
            'organization_id' => (int) $increase->organization_id,
            'limit_type' => $increase->limit_type->value,
            'limit_type_label' => $increase->limit_type->label(),
            'value_unit' => $unit,
            'current_value' => $increase->current_value,
            'requested_value' => $increase->requested_value,
            'justification' => $increase->justification,
            'status' => $increase->status->value,
            'failed_prerequisites' => array_values($increase->failed_prerequisites ?? []),
            'required_collateral_rial' => $increase->required_collateral_rial,
            'reviewed_at' => Display::iso($increase->reviewed_at),
            'next_review_at' => Display::iso($increase->next_review_at),
            'created_at' => Display::iso($increase->created_at),
        ] + $this->display($request, [
            'current_value_display' => $this->format($unit, $increase->current_value),
            'requested_value_display' => $this->format($unit, $increase->requested_value),
            'required_collateral_display' => Display::rial($increase->required_collateral_rial),
            'created_at_jalali' => Display::jalali($increase->created_at),
        ]);
    }

    private function unitOf(LimitIncreaseRequest $increase): string
    {
        if ($increase->limit_type->isWeightBased()) {
            return 'MILLIGRAM';
        }

        return $increase->limit_type->value === 'OPEN_ORDERS' ? 'COUNT' : 'RIAL';
    }

    private function format(string $unit, ?int $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($unit) {
            'MILLIGRAM' => Display::grams($value),
            'RIAL' => Display::rial($value),
            default => Display::digits((string) $value),
        };
    }
}
