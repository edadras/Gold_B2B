<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Application\SettingsAdminService;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'in:'.implode(',', array_keys(SettingsAdminService::EDITABLE))],
            'value' => ['required', 'string', 'max:255'],
            'note' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
