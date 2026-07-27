<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\AuthorityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /organization/representatives — 👑 OWNER. */
final class StoreRepresentativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:191'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'authority_type' => ['required', Rule::in(AuthorityType::values())],
            'daily_limit_mg' => ['nullable', 'integer', 'min:0'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'document_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
