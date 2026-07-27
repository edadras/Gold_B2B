<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /orders/cancel-all — 🔑 idempotent. */
final class CancelAllOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'instrument' => ['sometimes', 'nullable', 'string', 'max:50'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
