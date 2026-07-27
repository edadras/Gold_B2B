<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** Vault operations — docs/03-domain/06-custody-vault.md §6.5. */
enum CustodyOperationType: string
{
    case VAULT_IN = 'VAULT_IN';
    case VAULT_OUT = 'VAULT_OUT';
    case RELOCATE = 'RELOCATE';
    case VAULT_TRANSFER = 'VAULT_TRANSFER';
    case SPLIT = 'SPLIT';
    case MERGE = 'MERGE';
    case MELT = 'MELT';
    case SEND_TO_ASSAY = 'SEND_TO_ASSAY';
    case RECEIVE_ASSAY = 'RECEIVE_ASSAY';
    case HOLD = 'HOLD';
    case RELEASE = 'RELEASE';
    case AUDIT_COUNT = 'AUDIT_COUNT';

    /** Operations that consume input lots and emit new ones. */
    public function isTransformation(): bool
    {
        return match ($this) {
            self::SPLIT, self::MERGE, self::MELT => true,
            default => false,
        };
    }

    /** Operations that may legitimately produce a mass loss. */
    public function mayProduceLoss(): bool
    {
        return $this->isTransformation();
    }

    /** Operations that require a second pair of eyes before execution. */
    public function requiresDualApproval(): bool
    {
        return match ($this) {
            self::VAULT_OUT, self::VAULT_TRANSFER, self::MELT => true,
            default => false,
        };
    }
}
