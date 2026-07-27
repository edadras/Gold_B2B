<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Identity publishes IdentityDirectory (single-member reads) and
 * OrganizationLifecycle (status writes), but no member *listing*, which is what
 * an operator's organisation screen is. That gap is this port.
 */
interface OrganizationAdminPort
{
    /**
     * @param  list<string>  $statuses
     * @return list<OrganizationRow>
     */
    public function list(?string $search = null, array $statuses = [], int $limit = 100): array;

    public function find(int $organizationId): ?OrganizationRow;

    public function countByStatus(string $status): int;

    public function countRegisteredSince(string $since): int;
}
