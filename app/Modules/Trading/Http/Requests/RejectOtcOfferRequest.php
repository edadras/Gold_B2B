<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /otc-offers/{id}/reject and /cancel. */
final class RejectOtcOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
