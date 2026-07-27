<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * Identity's own verdicts on a member's registered identifiers — the
 * "بررسی‌های خودکار" panel of the compliance review screen
 * (docs/03-domain/01-identity-kyc.md §1.5).
 *
 * Identity computes these because it is the only module that may decrypt
 * `national_id_enc` / `legal_id_enc` or read their blind indexes. Kyc receives
 * the answers, never the inputs.
 *
 * A null verdict means "not applicable": an individual member has no legal id
 * to validate, and a legal entity need not carry a personal national id.
 */
final readonly class OrganizationIdentityChecks
{
    public function __construct(
        public ?bool $nationalIdValid,
        public ?bool $legalIdValid,
        public bool $duplicateNationalId,
        public bool $duplicateLegalId,
    ) {}

    /** Nothing on file is suspicious and everything present validates. */
    public function allClear(): bool
    {
        return $this->nationalIdValid !== false
            && $this->legalIdValid !== false
            && ! $this->duplicateNationalId
            && ! $this->duplicateLegalId;
    }

    /** @return array<string, bool|null> shape stored on the kyc_reviews row */
    public function toArray(): array
    {
        return [
            'national_id_valid' => $this->nationalIdValid,
            'legal_id_valid' => $this->legalIdValid,
            'duplicate_national_id' => $this->duplicateNationalId,
            'duplicate_legal_id' => $this->duplicateLegalId,
        ];
    }
}
