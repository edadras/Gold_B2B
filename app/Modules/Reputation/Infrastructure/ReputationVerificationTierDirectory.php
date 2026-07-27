<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Infrastructure;

use App\Modules\Reputation\Application\PublicProfileService;
use App\Modules\Shared\Contracts\VerificationTierDirectory;

/**
 * Adapter that lets Identity put `verification_tier` in the login response and
 * in `/auth/me` without importing this module.
 *
 * Shared declares the port, Reputation binds this adapter, and a deployment
 * slice without Reputation keeps Shared's null implementation — the field
 * simply disappears rather than breaking the login.
 *
 * The tier is the one reputation figure that is public by definition (§14.3),
 * so exposing it through a one-method port leaks nothing the member profile
 * does not already publish. Note what the port does NOT offer: no statistics,
 * no counters, no way to ask for another member's numbers.
 */
final class ReputationVerificationTierDirectory implements VerificationTierDirectory
{
    public function __construct(private readonly PublicProfileService $profiles) {}

    public function tierFor(int $organizationId): ?string
    {
        return $this->profiles->tier($organizationId);
    }
}
