<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /organization/users — 👑 OWNER invites a colleague. */
final class InviteUserRequest extends FormRequest
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
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'roles' => ['required', 'array', 'min:1'],
            // Platform roles are excluded at validation, not just at
            // authorisation: a member must never be able to name one.
            'roles.*' => [Rule::in(array_map(static fn (Role $r): string => $r->value, Role::organizationRoles()))],
        ];
    }

    /** @return list<Role> */
    public function roleEnums(): array
    {
        /** @var list<string> $roles */
        $roles = $this->safe()->input('roles', []);

        return array_values(array_filter(array_map(
            static fn (string $r): ?Role => Role::tryFrom($r),
            $roles,
        )));
    }
}
