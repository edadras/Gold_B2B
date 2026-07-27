<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Requests;

use App\Modules\Risk\Domain\LimitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /risk/limit-increase-request — docs/05-api/02-endpoints.md §2.15.
 *
 * `requested_value` is an integer in the unit of the limit being raised:
 * milligrams for the weight-based types, rial for OPEN_EXPOSURE_RIAL, a plain
 * count for OPEN_ORDERS. `integer` rather than `numeric` so "250.5" is a
 * validation error instead of a silently truncated half-milligram
 * (AGENT_BRIEF rule 1).
 *
 * The service rejects a request that does not actually raise the ceiling, and
 * runs the six prerequisites of §11.8 — none of that is duplicated here,
 * because a FormRequest that knew the current ceiling would be reading the risk
 * profile from the HTTP layer.
 */
final class LimitIncreaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit_type' => ['required', 'string', Rule::in(array_map(
                static fn (LimitType $t): string => $t->value,
                LimitType::cases(),
            ))],
            'requested_value' => ['required', 'integer', 'min:1'],
            'justification' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function limitType(): LimitType
    {
        return LimitType::from((string) $this->validated('limit_type'));
    }
}
