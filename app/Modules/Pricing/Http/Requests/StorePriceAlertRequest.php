<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Domain\AlertCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /price-alerts.
 *
 * `threshold` is an integer in rial for ABOVE/BELOW and in basis points for
 * CHANGE_PERCENT. It is never a percentage with a decimal point: 1.5% is 150.
 */
final class StorePriceAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'instrument' => ['required', 'string', 'max:50'],
            'condition' => ['required', Rule::in(array_map(static fn (AlertCondition $c): string => $c->value, AlertCondition::cases()))],
            'threshold' => ['required', 'integer', 'min:1'],
            'window_seconds' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'is_recurring' => ['sometimes', 'boolean'],
        ];
    }

    public function condition(): AlertCondition
    {
        return AlertCondition::from((string) $this->validated('condition'));
    }
}
