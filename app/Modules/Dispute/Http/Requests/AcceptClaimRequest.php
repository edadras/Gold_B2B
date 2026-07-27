<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /disputes/{id}/accept — 🔑 ✍️ §2.12 «پذیرش ادعا».
 *
 * The transaction signature travels in the `X-Transaction-Signature` header,
 * not in the body, so it is deliberately absent from these rules: keeping it
 * out of the body keeps it out of the idempotency request hash, so replaying a
 * stored response does not require a TOTP code that has since expired.
 */
final class AcceptClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function message(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
