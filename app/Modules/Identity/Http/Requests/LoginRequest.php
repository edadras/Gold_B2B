<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/login */
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
            'password' => ['required', 'string', 'max:255'],
            'device_id' => ['sometimes', 'string', 'max:100'],
            'device_name' => ['sometimes', 'string', 'max:100'],
            'platform' => ['sometimes', 'string', 'in:ANDROID,IOS,WEB'],
            'app_version' => ['sometimes', 'string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mobile.required' => 'شماره موبایل الزامی است.',
            'password.required' => 'رمز عبور الزامی است.',
        ];
    }
}
