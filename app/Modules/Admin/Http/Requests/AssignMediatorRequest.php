<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignMediatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mediator_user_id' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
