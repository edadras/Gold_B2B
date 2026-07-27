<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §1.11: «هر اقدام نیازمند یادداشت». A KYC decision without a written reason is
 * not a decision, so `notes` is required at the edge as well as in the service.
 */
final class KycDecisionRequest extends FormRequest
{
    public const MIN_NOTE_LENGTH = 10;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:APPROVED,REJECTED,INFO_REQUIRED'],
            'notes' => ['required', 'string', 'min:'.self::MIN_NOTE_LENGTH, 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'notes.required' => 'ثبت تصمیم بدون یادداشت مجاز نیست.',
            'notes.min' => 'یادداشت باید دست‌کم :min نویسه باشد.',
        ];
    }
}
