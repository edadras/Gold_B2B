<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /organization — 👑 OWNER.
 *
 * Identity fields (national id, legal id, type, status) are absent on purpose:
 * changing those is a KYC decision, not a profile edit, and letting an owner
 * rewrite the national id would defeat the blind-index uniqueness check.
 */
final class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'max:191'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'union_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'city' => ['sometimes', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'market_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'size:10'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'website' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
