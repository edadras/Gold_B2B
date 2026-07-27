<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /rfq-quotes/{id}/accept — 🔑 idempotent.
 *
 * Omitting `quantity_mg` accepts the whole quote; supplying less is a partial
 * acceptance, which RfqService refuses unless the RFQ allows it.
 */
final class AcceptRfqQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity_mg' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
