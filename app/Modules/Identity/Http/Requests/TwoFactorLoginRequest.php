<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/login/2fa
 *
 * The docs' sample calls the field `challenge_token` while the exception that
 * produces it calls it `challenge_id`. Both are accepted; `challenge_token` is
 * canonical because that is what the shipped client sends.
 */
final class TwoFactorLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required_without:challenge_id', 'string', 'max:64'],
            'challenge_id' => ['required_without:challenge_token', 'string', 'max:64'],
            'method' => ['sometimes', 'string', 'in:TOTP,SMS'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    public function challenge(): string
    {
        return (string) ($this->input('challenge_token') ?? $this->input('challenge_id'));
    }
}
