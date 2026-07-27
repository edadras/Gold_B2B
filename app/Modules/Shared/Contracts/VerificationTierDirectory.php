<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * The member's public verification tier (BRONZE … PLATINUM).
 *
 * The login response and /auth/me both carry it, but it is Reputation's
 * property and Identity may not import Reputation. Shared declares the port,
 * Reputation binds the adapter, and a deployment slice without Reputation
 * simply gets null.
 */
interface VerificationTierDirectory
{
    public function tierFor(int $organizationId): ?string;
}
