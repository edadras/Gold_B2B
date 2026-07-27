<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Contracts;

/**
 * The public surface other modules use to show reputation at a decision point —
 * an RFQ quote list, an incoming OTC offer (§14.7).
 *
 * Returning `PublicProfile` rather than the raw statistics row is the whole
 * point: a caller cannot render a field it was never given.
 */
interface ReputationDirectory
{
    public function profile(int $organizationId): PublicProfile;

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, PublicProfile> keyed by organisation id
     */
    public function profiles(array $organizationIds): array;

    public function tier(int $organizationId): string;
}
