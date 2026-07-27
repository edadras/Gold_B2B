<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
            // Present only when the account has 2FA confirmed; validated in the
            // controller against the account's own secret.
            'code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
