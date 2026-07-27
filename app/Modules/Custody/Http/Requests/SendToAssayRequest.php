<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /lots/{id}/send-to-assay` — 🔑 idempotent.
 *
 * The laboratory is optional: the member may nominate one, or leave the choice
 * to the vault operator, which is the common case. The lot itself comes from
 * the route, never from the body.
 */
final class SendToAssayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'laboratory_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
