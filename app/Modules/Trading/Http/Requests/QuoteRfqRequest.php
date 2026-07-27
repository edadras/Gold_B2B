<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /rfqs/{id}/quotes — 🔑 idempotent. */
final class QuoteRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity_mg' => ['required', 'integer', 'min:1'],
            'price_per_gram_rial' => ['required', 'integer', 'min:1'],
            'valid_for_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
