<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /otc-offers/{id}/counter — 🔑 idempotent. */
final class CounterOtcOfferRequest extends FormRequest
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
            'price_rial' => ['required', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
