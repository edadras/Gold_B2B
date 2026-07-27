<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/**
 * Aggregates the figures behind the credit score. Reputation, Settlement, Kyc
 * and Dispute each own a slice of it; a composite implementation stitches them
 * together and Risk stays ignorant of all four.
 */
interface MemberActivityReaderInterface
{
    public function statsFor(int $organizationId): MemberActivityStats;
}
