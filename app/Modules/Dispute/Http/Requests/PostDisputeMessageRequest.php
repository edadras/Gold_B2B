<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /disputes/{id}/messages — §2.12 «پیام در مذاکره».
 *
 * Plain talk only. A settlement offer is NOT a message with a number in it —
 * accepting one produces a binding verdict with no operator in the loop, so it
 * has its own endpoint and carries its amounts as fields rather than as prose
 * (see ProposeSettlementRequest).
 */
final class PostDisputeMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
