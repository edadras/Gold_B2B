<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /settlements/{id}/cancel — 🔑 idempotent. */
final class CancelSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
