<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AdjustmentDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision_note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision_note.required' => 'تأیید یا رد بدون یادداشت مجاز نیست.',
        ];
    }
}
