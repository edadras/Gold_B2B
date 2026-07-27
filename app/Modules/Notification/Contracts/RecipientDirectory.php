<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

/**
 * Where recipients come from.
 *
 * Identity owns users and roles, and this module reads them — but through a
 * port, not through Identity's Eloquent models, which are not ours to touch.
 * The port also lets a test or a deployment without Identity supply recipients
 * from memory.
 */
interface RecipientDirectory
{
    /** @return list<Recipient> */
    public function usersInOrganization(int $organizationId): array;

    /**
     * Members of the organisation holding any of the given roles.
     *
     * @param  list<string>  $roles
     * @return list<Recipient>
     */
    public function usersWithRoles(int $organizationId, array $roles): array;

    public function find(int $userId): ?Recipient;
}
