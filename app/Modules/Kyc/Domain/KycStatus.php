<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain;

/**
 * KYC dossier lifecycle — docs/11-appendix/02-state-machines.md §2.12.
 *
 * Distinct from OrganizationStatus on purpose: the dossier is re-reviewed
 * periodically (APPROVED → IN_REVIEW) without the member losing ACTIVE status,
 * and a member can be SUSPENDED for reasons that have nothing to do with KYC.
 */
enum KycStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case IN_REVIEW = 'IN_REVIEW';
    case INFO_REQUIRED = 'INFO_REQUIRED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::SUBMITTED],
            // A member may pull the dossier back to DRAFT to edit it, as long
            // as an officer has not picked it up yet.
            self::SUBMITTED => [self::IN_REVIEW, self::DRAFT],
            self::IN_REVIEW => [self::APPROVED, self::INFO_REQUIRED, self::REJECTED],
            self::INFO_REQUIRED => [self::SUBMITTED],
            // Periodic re-review (docs §1.6) reopens an approved dossier.
            self::APPROVED => [self::IN_REVIEW],
            self::REJECTED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Whether the member may still edit the dossier's contents. */
    public function isEditableByMember(): bool
    {
        return in_array($this, [self::DRAFT, self::INFO_REQUIRED], true);
    }

    /** Whether the dossier is sitting in the compliance queue. */
    public function isAwaitingOfficer(): bool
    {
        return in_array($this, [self::SUBMITTED, self::IN_REVIEW], true);
    }
}
