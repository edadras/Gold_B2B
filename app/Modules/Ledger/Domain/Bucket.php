<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Where a quantity currently sits in its lifecycle (docs/03-domain/03-ledger.md §3.5).
 *
 * Value never leaves the organisation when it changes bucket — a bucket move is
 * always a balanced pair of entries in one transaction_group, so conservation
 * of mass is preserved by construction.
 */
enum Bucket: string
{
    case AVAILABLE = 'AVAILABLE';
    case RESERVED = 'RESERVED';
    case IN_SETTLEMENT = 'IN_SETTLEMENT';
    case IN_DISPUTE = 'IN_DISPUTE';
    case PAYABLE = 'PAYABLE';

    /** @return array<int, self> */
    public static function forAsset(AssetType $asset): array
    {
        return match ($asset) {
            // PAYABLE models a debt position and only exists in rial.
            AssetType::GOLD => [self::AVAILABLE, self::RESERVED, self::IN_SETTLEMENT, self::IN_DISPUTE],
            AssetType::RIAL => [self::AVAILABLE, self::RESERVED, self::IN_SETTLEMENT, self::IN_DISPUTE, self::PAYABLE],
        };
    }

    /**
     * Whether a member account in this bucket may hold a negative balance.
     *
     * Invariant I3: AVAILABLE / RESERVED / IN_SETTLEMENT must never go below
     * zero. IN_DISPUTE is held to the same rule; only PAYABLE may be negative.
     */
    public function allowsNegative(): bool
    {
        return $this === self::PAYABLE;
    }

    /** Buckets counted by invariant I3. */
    public function mustStayNonNegative(): bool
    {
        return ! $this->allowsNegative();
    }

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'در دسترس',
            self::RESERVED => 'رزروشده',
            self::IN_SETTLEMENT => 'در جریان تسویه',
            self::IN_DISPUTE => 'در اختلاف',
            self::PAYABLE => 'بدهی',
        };
    }
}
