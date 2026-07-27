<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/**
 * Reconciliation outcome per counted line —
 * docs/03-domain/06-custody-vault.md §6.6 thresholds.
 */
enum VarianceClassification: string
{
    /** Weight matches exactly. */
    case MATCHED = 'MATCHED';

    /** < 0.01% — scale noise, record only. */
    case WITHIN_TOLERANCE = 'WITHIN_TOLERANCE';

    /** 0.01%..0.1% — review, ledger adjustment needs dual approval. */
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /** > 0.1% — halt and investigate. */
    case INVESTIGATION_REQUIRED = 'INVESTIGATION_REQUIRED';

    /** Expected but not found — critical, freeze the vault. */
    case MISSING = 'MISSING';

    /** Found but not on the books — halt and trace its origin. */
    case UNKNOWN_LOT = 'UNKNOWN_LOT';

    public function isVariance(): bool
    {
        return $this !== self::MATCHED && $this !== self::WITHIN_TOLERANCE;
    }

    public function haltsOperations(): bool
    {
        return match ($this) {
            self::INVESTIGATION_REQUIRED, self::MISSING, self::UNKNOWN_LOT => true,
            default => false,
        };
    }

    public function freezesVault(): bool
    {
        return $this === self::MISSING;
    }
}
