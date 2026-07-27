<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /organization/users/{id}/limits — 👑 OWNER, docs §2.2.
 *
 * Weights are integer milligrams, per AGENT_BRIEF rule 1. `integer` rather than
 * `numeric`: "250.5" must be a validation error, not a silently truncated
 * quarter-gram.
 *
 * All four keys are optional so the endpoint can raise one ceiling without the
 * client having to echo the others back. An explicit null on a weight means
 * "fall back to the member-level ceiling"; UserLimitService resolves that,
 * because the column itself is NOT NULL.
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
