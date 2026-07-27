<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /settlements/{id}/confirm-payment — 🔑 idempotent, ✍️ signed. */
final class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
