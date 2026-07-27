<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\RegisterOrganizationService;
use App\Modules\Identity\Domain\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /auth/register — the organisation and its OWNER are created together.
 *
 * A member never exists without an owner, so splitting this into two calls
 * would leave an orphan organisation whenever the second call failed.
 */
final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(OrganizationType::values())],
            'display_name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'legal_id' => ['nullable', 'string', 'max:20'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'established_at' => ['nullable', 'date'],
            'union_name' => ['nullable', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'market_name' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'size:10'],
            'phone' => ['nullable', 'string', 'max:20'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'website' => ['nullable', 'string', 'max:191'],

            'owner' => ['required', 'array'],
            'owner.full_name' => ['required', 'string', 'max:191'],
            'owner.mobile' => ['required', 'string', 'max:20'],
            'owner.email' => ['nullable', 'email', 'max:191'],
            'owner.national_id' => ['nullable', 'string', 'max:20'],
            'owner.password' => ['required', 'string', 'min:'.RegisterOrganizationService::minimumPasswordLength(), 'max:255', 'confirmed'],
        ];
    }

    /** @return array<string, mixed> */
    public function organizationAttributes(): array
    {
        return $this->safe()->except(['owner', 'owner.password_confirmation']);
    }

    /** @return array<string, mixed> */
    public function ownerAttributes(): array
    {
        /** @var array<string, mixed> $owner */
        $owner = $this->safe()->input('owner', []);

        unset($owner['password_confirmation']);

        return $owner;
    }
}
