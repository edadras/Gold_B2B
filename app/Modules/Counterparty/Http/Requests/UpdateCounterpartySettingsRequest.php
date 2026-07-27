<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /counterparties/{orgId}/settings — "اعتماد/بلاک", docs §2.11.
 *
 * Every flag is optional, and an absent key means "leave it alone" rather than
 * "set it false": the screen has three independent toggles and sending two of
 * them must not silently clear the third.
 *
 * Trusted-and-blocked at once is refused by RelationService, not here — the
 * contradiction depends on the stored state as well as the payload, so only the
 * service can see it.
 */
final class UpdateCounterpartySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_trusted' => ['sometimes', 'nullable', 'boolean'],
            'is_blocked' => ['sometimes', 'nullable', 'boolean'],
            'auto_accept_otc' => ['sometimes', 'nullable', 'boolean'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function flag(string $key): ?bool
    {
        $value = $this->validated($key);

        return $value === null ? null : (bool) $value;
    }
}
