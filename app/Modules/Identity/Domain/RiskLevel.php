<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Coarse member risk band. The Risk module owns the scoring; Identity only
 * stores the resulting band because it drives the KYC re-review period
 * (docs/03-domain/01-identity-kyc.md §1.6).
 */
enum RiskLevel: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Periodic KYC re-review interval in months. CRITICAL follows HIGH. */
    public function reviewIntervalMonths(): int
    {
        return match ($this) {
            self::LOW => 36,
            self::MEDIUM => 24,
            self::HIGH, self::CRITICAL => 12,
        };
    }
}
