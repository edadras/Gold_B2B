<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Exceptions\LimitExceededException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The `?from=&to=` pair every dated report takes (§2.13).
 *
 * NOTE ON THE ONE-YEAR CAP. `from` and `to` are validated for shape and order
 * here, but NOT for width. The 366-day limit belongs to DateRange, which throws
 * LimitExceededException from its constructor, and that exception already
 * renders as the documented `422 LIMIT_EXCEEDED` envelope with the requested
 * and permitted day counts in `details`. Re-checking it here would produce a
 * second, less informative error for the same rule and let the two drift apart.
 */
final class ReportRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @throws LimitExceededException beyond 366 days */
    public function range(): DateRange
    {
        return DateRange::of(
            (string) $this->validated('from'),
            (string) $this->validated('to'),
        );
    }
}
