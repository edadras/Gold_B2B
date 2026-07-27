<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * GET /counterparties/{orgId}/statement?from=&to= — docs §2.11.
 *
 * Both bounds are optional. A statement request with no window is the common
 * case from the mobile client's "صورت‌حساب" button, and defaulting to the last
 * month is friendlier than a 422; the response echoes the window it used, so
 * nothing is ambiguous.
 */
final class StatementRequest extends FormRequest
{
    /** Default window when the caller names neither bound. */
    private const DEFAULT_DAYS = 30;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function from(): Carbon
    {
        $value = $this->validated('from');

        return $value === null ? $this->to()->copy()->subDays(self::DEFAULT_DAYS) : Carbon::parse((string) $value);
    }

    public function to(): Carbon
    {
        $value = $this->validated('to');

        return $value === null ? Carbon::now() : Carbon::parse((string) $value);
    }
}
