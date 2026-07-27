<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * The read-only view of a member that other modules are allowed to hold.
 *
 * Scalars only: handing out an Eloquent model would let Trading or Settlement
 * write to Identity's tables, which AGENT_BRIEF rule 7 forbids.
 */
final readonly class OrganizationSnapshot
{
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public string $displayName,
        public string $riskLevel,
        public bool $canTrade,
        public bool $canSettle,
    ) {}
}
