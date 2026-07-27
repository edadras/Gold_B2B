<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /organization/licenses` — 👑 OWNER.
 *
 * `status` is absent by design: a member declaring its own licence VALID would
 * walk straight past the compliance check. LicenseService derives it.
 */
final class StoreLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'license_no' => ['required', 'string', 'max:64'],
            'issuing_union' => ['required', 'string', 'max:191'],
            'activity_type' => ['sometimes', 'nullable', 'string', 'max:191'],
            'premises_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:issued_at'],
            'document_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
