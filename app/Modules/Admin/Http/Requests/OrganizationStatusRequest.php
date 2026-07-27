<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Application\OrganizationAdminService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspending or restricting a member cuts off their ability to trade, so the
 * reason is mandatory and long enough to be an explanation rather than a word.
 */
final class OrganizationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', OrganizationAdminService::ALLOWED_TRANSITIONS)],
            'reason' => ['required', 'string', 'min:15', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'تغییر وضعیت عضو بدون دلیل مجاز نیست.',
        ];
    }
}
