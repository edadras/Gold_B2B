<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /organization/users/{id}/roles */
final class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array'],
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
