<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /organization/users/{id}/limits
 *
 * Weights are integer milligrams, per AGENT_BRIEF rule 1. `integer` rather than
 * `numeric`: "250.5" must be a validation error, not a silently truncated
 * quarter-gram.
 */
final class UpdateUserLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'max_order_mg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_daily_volume_mg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'requires_approval_above_mg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
