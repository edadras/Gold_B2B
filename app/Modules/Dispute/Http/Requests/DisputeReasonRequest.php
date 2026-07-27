<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional free-text reason shared by `/withdraw` and `/escalate` (§2.12).
 *
 * One class for both because the payload really is the same single field; the
 * two actions differ in what they do, not in what they accept, and two
 * identical FormRequests would drift apart the first time one of them gained a
 * rule.
 */
final class DisputeReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
