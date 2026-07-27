<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\RegisterOrganizationService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/password/reset
 *
 * The ticket comes from /auth/otp/verify with purpose PASSWORD_RESET. A bare
 * OTP code is deliberately not accepted here: that would let the same six
 * digits be replayed against several endpoints.
 */
final class ResetPasswordRequest extends FormRequest
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
            'ticket' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:'.RegisterOrganizationService::minimumPasswordLength(), 'max:255', 'confirmed'],
        ];
    }
}
