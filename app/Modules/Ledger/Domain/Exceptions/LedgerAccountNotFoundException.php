<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * An organisation is missing a ledger account it should have.
 *
 * Accounts are provisioned once, when the organisation is activated
 * (CreateLedgerAccountsForOrganization). Hitting this means provisioning was
 * skipped, so it is an operational fault rather than a user error.
 */
final class LedgerAccountNotFoundException extends DomainException
{
    public function __construct(
        public readonly int $organizationId,
        public readonly AssetType $asset,
        public readonly Bucket $bucket,
    ) {
        parent::__construct(sprintf(
            'No %s/%s ledger account for organization %d',
            $asset->value,
            $bucket->value,
            $organizationId,
        ));
    }

    public function errorCode(): string
    {
        return 'LEDGER_ACCOUNT_NOT_FOUND';
    }

    public function userMessage(): string
    {
        return 'حساب دفتر کل برای این عملیات یافت نشد.';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public function details(): array
    {
        return [
            'organizationId' => $this->organizationId,
            'assetType' => $this->asset->value,
            'bucket' => $this->bucket->value,
        ];
    }
}
