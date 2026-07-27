<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\Exceptions\ForbiddenException;

/**
 * The HTTP layer's view of "may this caller do this to this resource?".
 *
 * Identity owns the answer, but Shared may not depend on Identity (the module
 * graph in tests/Architecture/ArchitectureTest.php has Shared depending on
 * nothing). So Shared declares the port and Identity binds the adapter — the
 * same inversion Pricing uses for TradePrintSourceInterface.
 *
 * Every implementation MUST check BOTH legs:
 *   1. tenancy  — the resource's organization is the caller's organization;
 *   2. role     — the caller's roles grant the named permission.
 * A tenant-scoped query alone is not sufficient: withoutGlobalScope() or a raw
 * query bypasses a scope, and a role check alone would let a TRADER touch
 * another member's order (docs/02-architecture/04-security.md §4.3).
 */
interface AuthorizationGateway
{
    /**
     * @param  string  $permission  dotted permission name, e.g. "order.create"
     * @param  int|null  $resourceOrganizationId  organization owning the resource;
     *                                            null means "the caller's own"
     */
    public function allows(int $userId, string $permission, ?int $resourceOrganizationId = null): bool;

    /** @throws ForbiddenException */
    public function authorize(int $userId, string $permission, ?int $resourceOrganizationId = null): void;

    /** @return list<string> dotted permission names held by the user */
    public function permissionsFor(int $userId): array;

    /** @return list<string> role names held by the user */
    public function rolesFor(int $userId): array;
}
