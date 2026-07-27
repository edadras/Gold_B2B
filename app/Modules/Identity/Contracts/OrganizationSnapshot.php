<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\RiskLevel;

/**
 * The read-only view of a member that other modules are allowed to hold.
 *
 * Scalars only: handing out an Eloquent model would let Trading or Settlement
 * write to Identity's tables, which AGENT_BRIEF rule 7 forbids.
 *
 * The status/type/risk fields stay plain strings so a consumer can serialise
 * the snapshot without a second thought; the accessors below hand back the
 * Domain enums for callers that want the behaviour attached to them (Domain is
 * part of Identity's public surface, so that crosses no boundary).
 *
 * Nothing here reveals an encrypted identity column. `hasNationalId` /
 * `hasLegalId` answer "is one on file?" — which is all a completeness check
 * needs — without exposing the value or its blind index.
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
        public ?string $registrationNo = null,
        public bool $hasNationalId = false,
        public bool $hasLegalId = false,
        public bool $isPlatform = false,
    ) {}

    public function organizationType(): OrganizationType
    {
        return OrganizationType::from($this->type);
    }

    public function organizationStatus(): OrganizationStatus
    {
        return OrganizationStatus::from($this->status);
    }

    public function organizationRiskLevel(): RiskLevel
    {
        return RiskLevel::from($this->riskLevel);
    }
}
