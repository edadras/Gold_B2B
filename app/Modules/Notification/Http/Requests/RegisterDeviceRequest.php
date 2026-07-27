<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /devices — register an FCM/APNs token for push. */
final class RegisterDeviceRequest extends FormRequest
{
    /** The enum on `push_devices.platform`; keep the two in step. */
    public const PLATFORMS = ['ANDROID', 'IOS', 'WEB'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(self::PLATFORMS)],
            // 255 is the column width; a provider token longer than that would
            // be silently truncated and then never match on revoke.
            'token' => ['required', 'string', 'min:8', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
