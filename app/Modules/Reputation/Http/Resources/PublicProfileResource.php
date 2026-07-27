<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Http\Resources;

use App\Modules\Reputation\Contracts\PublicProfile;
use App\Modules\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * `GET /members/{id}/reputation` (docs §2.11).
 *
 * The body is `PublicProfile::toArray()` VERBATIM. Nothing is added — not even
 * a `_display` companion — and that is a deliberate constraint, not an
 * oversight:
 *
 *   · `PublicProfile` is the allow-list that docs/03-domain/14-reputation.md
 *     §14.9 requires, built field by field in PublicProfileService and never
 *     from a model or a snapshot. A resource that assembled its own field list
 *     would be a second, weaker allow-list, and the day a column is added to
 *     `reputation_stats` the two would disagree.
 *   · PublicProfilePrivacyTest asserts the exact key set of that array. Adding
 *     a key here would let the HTTP payload drift away from the payload the
 *     privacy test guards, which is the one thing this endpoint must not do.
 *
 * Rates are basis points (9,980 = 99.8%), so a client that wants "۹۹.۸٪"
 * formats it locally.
 *
 * @mixin PublicProfile
 */
final class PublicProfileResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PublicProfile $profile */
        $profile = $this->resource;

        return $profile->toArray();
    }
}
