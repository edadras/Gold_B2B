<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\RegisterOrganizationService;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /auth/password */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.RegisterOrganizationService::minimumPasswordLength(), 'max:255', 'confirmed', 'different:current_password'],
        ];
    }
}
