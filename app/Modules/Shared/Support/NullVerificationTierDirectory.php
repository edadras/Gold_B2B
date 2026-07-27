<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\VerificationTierDirectory;

/**
 * Default binding: no tier information. Reputation replaces it when present.
 *
 * Returning null rather than "BRONZE" is deliberate — the client must be able
 * to distinguish "not scored yet" from "scored lowest".
 */
final class NullVerificationTierDirectory implements VerificationTierDirectory
{
    public function tierFor(int $organizationId): ?string
    {
        return null;
    }
}
