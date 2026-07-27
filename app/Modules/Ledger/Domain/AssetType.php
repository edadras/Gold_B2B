<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use InvalidArgumentException;

/**
 * The two asset classes the ledger is denominated in.
 *
 * GOLD is measured in milligrams of pure gold (FineWeight), RIAL in rial.
 * Conservation of mass (docs/03-domain/03-ledger.md §3.3) holds per asset type,
 * never across them.
 */
enum AssetType: string
{
    case GOLD = 'GOLD';
    case RIAL = 'RIAL';

    /** Smallest indivisible unit, used in messages and reports. */
    public function unit(): string
    {
        return match ($this) {
            self::GOLD => 'mg',
            self::RIAL => 'IRR',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GOLD => 'طلا',
            self::RIAL => 'ریال',
        };
    }

    /** Buckets that exist for this asset type. PAYABLE is rial-only. */
    public function defaultMetalType(): ?MetalType
    {
        return match ($this) {
            self::GOLD => MetalType::GOLD,
            self::RIAL => null,
        };
    }

    /**
     * Wrap a raw signed integer amount in the value object for this asset.
     *
     * Ledger amounts are signed but FineWeight is not, so gold amounts are
     * exposed as absolute weights; callers that need the sign read the int.
     */
    public function wrap(int $amount): FineWeight|Rial
    {
        return match ($this) {
            self::GOLD => FineWeight::fromMilligrams(max(0, $amount)),
            self::RIAL => Rial::fromRial($amount),
        };
    }

    /**
     * Unwrap a caller-supplied amount into the raw integer this asset stores.
     *
     * Rejects the wrong value object outright: handing a Rial to the gold
     * ledger is exactly the class of bug the type split exists to prevent.
     */
    public function unwrap(FineWeight|Rial|int $amount): int
    {
        return match (true) {
            is_int($amount) => $amount,
            $amount instanceof FineWeight && $this === self::GOLD => $amount->milligrams,
            $amount instanceof Rial && $this === self::RIAL => $amount->amount,
            default => throw new InvalidArgumentException(
                sprintf('Cannot use %s as a %s ledger amount', $amount::class, $this->value)
            ),
        };
    }
}
