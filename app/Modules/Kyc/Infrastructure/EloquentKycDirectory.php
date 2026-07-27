<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure;

use App\Modules\Kyc\Contracts\KycDirectory;
use App\Modules\Kyc\Domain\BankAccountStatus;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Infrastructure\Models\KycProfile;

final class EloquentKycDirectory implements KycDirectory
{
    public function kycStatus(int $organizationId): ?string
    {
        $profile = KycProfile::query()->where('organization_id', $organizationId)->first();

        return $profile?->status->value;
    }

    public function isKycApproved(int $organizationId): bool
    {
        return $this->kycStatus($organizationId) === KycStatus::APPROVED->value;
    }

    public function daysUntilLicenseExpiry(int $organizationId): ?int
    {
        $license = BusinessLicense::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('expires_at')
            ->first();

        return $license?->daysUntilExpiry();
    }

    public function primaryBankIban(int $organizationId): ?string
    {
        $account = BankAccount::query()
            ->where('organization_id', $organizationId)
            ->where('status', BankAccountStatus::VERIFIED->value)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($account === null) {
            return null;
        }

        $iban = $account->iban_enc;

        return $iban === null ? null : (string) $iban;
    }
}
