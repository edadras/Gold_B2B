<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Notification\Contracts\Recipient;
use App\Modules\Notification\Contracts\RecipientDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads recipients from Identity's tables.
 *
 * By table, not by model: `users`, `roles` and `role_user` belong to Identity,
 * and reaching for its Eloquent classes would couple the two modules'
 * internals. The read is confined to this one class so the coupling has exactly
 * one place to break, and `RecipientDirectory` lets a deployment substitute
 * another source entirely.
 *
 * Suspended and pending users are excluded: a notification is a call to act,
 * and an account that cannot act should not be paged at 3am.
 */
final class DatabaseRecipientDirectory implements RecipientDirectory
{
    /** @return list<Recipient> */
    public function usersInOrganization(int $organizationId): array
    {
        if (! Schema::hasTable('users')) {
            return [];
        }

        $rows = DB::table('users')
            ->where('organization_id', $organizationId)
            ->where('status', UserStatus::ACTIVE->value)
            ->orderBy('id')
            ->get(['id', 'organization_id', 'mobile', 'email']);

        return $this->hydrate($rows->all());
    }

    /**
     * @param  list<string>  $roles
     * @return list<Recipient>
     */
    public function usersWithRoles(int $organizationId, array $roles): array
    {
        if ($roles === [] || ! Schema::hasTable('users') || ! Schema::hasTable('role_user')) {
            return [];
        }

        $rows = DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('users.organization_id', $organizationId)
            ->where('users.status', UserStatus::ACTIVE->value)
            // The pivot carries organization_id so tenancy and permission are
            // checked in the same query (docs/02-architecture/04-security.md §4.3).
            ->where('role_user.organization_id', $organizationId)
            ->whereIn('roles.name', $roles)
            ->where(function ($query): void {
                $query->whereNull('role_user.expires_at')
                    ->orWhere('role_user.expires_at', '>', now());
            })
            ->distinct()
            ->orderBy('users.id')
            ->get(['users.id', 'users.organization_id', 'users.mobile', 'users.email']);

        return $this->hydrate($rows->all());
    }

    public function find(int $userId): ?Recipient
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        $row = DB::table('users')
            ->where('id', $userId)
            ->first(['id', 'organization_id', 'mobile', 'email']);

        if ($row === null) {
            return null;
        }

        return $this->hydrate([$row])[0] ?? null;
    }

    /**
     * @param  list<object>  $rows
     * @return list<Recipient>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $userIds = array_map(static fn (object $row): int => (int) $row->id, $rows);

        $rolesByUser = $this->rolesFor($userIds);
        $tokensByUser = $this->pushTokensFor($userIds);

        return array_map(
            static fn (object $row): Recipient => new Recipient(
                id: (int) $row->id,
                organizationId: (int) $row->organization_id,
                roles: $rolesByUser[(int) $row->id] ?? [],
                mobile: isset($row->mobile) ? (string) $row->mobile : null,
                email: isset($row->email) && $row->email !== null ? (string) $row->email : null,
                pushTokens: $tokensByUser[(int) $row->id] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function rolesFor(array $userIds): array
    {
        if (! Schema::hasTable('role_user') || ! Schema::hasTable('roles')) {
            return [];
        }

        $rows = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('role_user.user_id', $userIds)
            ->get(['role_user.user_id', 'roles.name']);

        $roles = [];

        foreach ($rows as $row) {
            $roles[(int) $row->user_id][] = (string) $row->name;
        }

        return $roles;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function pushTokensFor(array $userIds): array
    {
        if (! Schema::hasTable('push_devices')) {
            return [];
        }

        $rows = DB::table('push_devices')
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->get(['user_id', 'token']);

        $tokens = [];

        foreach ($rows as $row) {
            $tokens[(int) $row->user_id][] = (string) $row->token;
        }

        return $tokens;
    }
}
