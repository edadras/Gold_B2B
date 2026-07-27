<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Infrastructure\Tables\TableAmlAdminAdapter;
use Illuminate\Foundation\Http\FormRequest;

final class AmlDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', TableAmlAdminAdapter::DECISION_STATUSES)],
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:255'],
        ];
    }
}
