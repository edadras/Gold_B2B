<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/otp/send */
final class SendOtpRequest extends FormRequest
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
            'purpose' => ['sometimes', 'string', 'in:REGISTER,LOGIN,PASSWORD_RESET,VERIFY_MOBILE'],
        ];
    }

    public function purpose(): string
    {
        return (string) $this->input('purpose', 'VERIFY_MOBILE');
    }
}
