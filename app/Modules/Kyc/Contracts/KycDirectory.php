<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Contracts;

/**
 * Read-only questions other modules may ask about a member's KYC standing.
 * Settlement needs the verified IBAN; Risk needs the licence horizon.
 */
interface KycDirectory
{
    public function kycStatus(int $organizationId): ?string;

    public function isKycApproved(int $organizationId): bool;

    /** Days until the member's best licence lapses; null when none on file. */
    public function daysUntilLicenseExpiry(int $organizationId): ?int;

    /** Canonical IBAN of the verified primary account, or null. */
    public function primaryBankIban(int $organizationId): ?string;
}
