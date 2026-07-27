<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

/**
 * The counter-leg a manual adjustment is booked against
 * (docs/08-frontend-web/01-web-panels.md §1.10, the `offset_account` select).
 *
 * Values mirror Ledger's SystemAccountCode. They are duplicated rather than
 * imported because Admin may depend on Shared and Identity only; the values are
 * a data contract (the `system_account_code` column), so a mismatch fails loudly
 * at posting time when the account lookup returns nothing.
 */
enum OffsetAccount: string
{
    case SUSPENSE = 'SUSPENSE';
    case ROUNDING_DIFFERENCE = 'ROUNDING_DIFFERENCE';
    case ASSAY_VARIANCE = 'ASSAY_VARIANCE';
    case PROCESSING_LOSS = 'PROCESSING_LOSS';

    public function label(): string
    {
        return match ($this) {
            self::SUSPENSE => 'حساب معلق',
            self::ROUNDING_DIFFERENCE => 'تفاوت گِردکردن',
            self::ASSAY_VARIANCE => 'اختلاف ری‌گیری',
            self::PROCESSING_LOSS => 'افت فرآوری',
        };
    }

    /**
     * Assets this offset account exists for.
     *
     * ASSAY_VARIANCE and PROCESSING_LOSS are gold-only concepts and only gold
     * rows are seeded for them, so offering them for a rial adjustment would
     * produce a request that can never be posted.
     *
     * @return list<AdjustmentAsset>
     */
    public function assets(): array
    {
        return match ($this) {
            self::SUSPENSE, self::ROUNDING_DIFFERENCE => [AdjustmentAsset::GOLD, AdjustmentAsset::RIAL],
            self::ASSAY_VARIANCE, self::PROCESSING_LOSS => [AdjustmentAsset::GOLD],
        };
    }

    public function supports(AdjustmentAsset $asset): bool
    {
        return in_array($asset, $this->assets(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
