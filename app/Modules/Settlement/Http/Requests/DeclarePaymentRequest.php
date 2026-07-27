<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /settlements/{id}/declare-payment — 🔑 idempotent.
 *
 * Shape from the sample in docs/05-api/02-endpoints.md §2.8. `amount_rial` is
 * an integer: a bank transfer of 19,649,430,000 rial has no fractional part and
 * accepting a decimal would invite a float somewhere downstream.
 */
final class DeclarePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_reference' => ['required', 'string', 'max:100'],
            'amount_rial' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            'bank_transaction_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'receipt_document_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
