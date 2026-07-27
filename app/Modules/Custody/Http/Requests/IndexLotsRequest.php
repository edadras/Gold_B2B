<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Requests;

use App\Modules\Custody\Domain\Enums\LotStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /lots?status=AVAILABLE`.
 *
 * The filter is validated rather than passed through so an unknown value is a
 * field error instead of an empty list — silently returning nothing for a typo
 * is the kind of thing that gets read as "my gold is gone".
 */
final class IndexLotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(LotStatus::values())],
        ];
    }

    public function statusFilter(): ?LotStatus
    {
        $status = $this->validated('status');

        return is_string($status) && $status !== '' ? LotStatus::from($status) : null;
    }
}
