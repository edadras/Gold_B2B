<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use App\Modules\Settlement\Domain\SettlementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /settlements — filters per docs/05-api/01-conventions.md §1.9. */
final class SettlementIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::in(SettlementStatus::values())],
        ];
    }

    public function status(): ?SettlementStatus
    {
        $status = $this->input('filter.status');

        return is_string($status) ? SettlementStatus::tryFrom($status) : null;
    }
}
