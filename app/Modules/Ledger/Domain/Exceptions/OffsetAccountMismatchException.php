<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * A manual adjustment named a system offset account that holds no balance in
 * that asset — ASSAY_VARIANCE or PROCESSING_LOSS for a rial correction.
 *
 * Refused at the boundary rather than left to fail on the account lookup inside
 * the group writer, so the operator is told which accounts *would* work while
 * the form is still in front of them.
 */
final class OffsetAccountMismatchException extends DomainException
{
    public function __construct(
        public readonly SystemAccountCode $offset,
        public readonly AssetType $asset,
    ) {
        parent::__construct(sprintf(
            'System account %s holds no %s balance and cannot offset this adjustment',
            $offset->value,
            $asset->value,
        ));
    }

    public function errorCode(): string
    {
        return 'OFFSET_ACCOUNT_ASSET_MISMATCH';
    }

    public function userMessage(): string
    {
        return 'حساب طرف مقابل انتخاب‌شده برای این نوع دارایی تعریف نشده است.';
    }

    public function details(): array
    {
        return [
            'offsetAccount' => $this->offset->value,
            'assetType' => $this->asset->value,
        ];
    }
}
