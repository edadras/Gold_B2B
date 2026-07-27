<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /counterparties/{orgId}/limits — 👑, docs §2.11.
 *
 * The limit is what *I* am willing to be owed by *you*
 * (docs/03-domain/10-counterparty.md §10.5). Gold is integer milligrams and
 * money is integer rial; `integer` rather than `numeric` so "250.5" is refused
 * instead of silently truncated (AGENT_BRIEF rule 1). Neither may be negative —
 * the service enforces that too, but a 422 with field_errors is a better answer
 * than a domain exception for something the client can check itself.
 */
final class UpdateCounterpartyLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gold_limit_mg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rial_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
