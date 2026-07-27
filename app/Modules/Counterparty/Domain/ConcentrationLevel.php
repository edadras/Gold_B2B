<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Domain;

/**
 * Severity of counterparty concentration (docs/03-domain/10-counterparty.md §10.6).
 *
 * Defaults: warn at 40% of total receivable held with one counterparty, serious
 * at 60%. Operators override both through `goldb2b.counterparty.*`; the
 * constants below exist so the thresholds have one documented home rather than
 * being spelled out at each call site.
 */
enum ConcentrationLevel: string
{
    case NORMAL = 'NORMAL';
    case WARNING = 'WARNING';
    case SERIOUS = 'SERIOUS';

    public const DEFAULT_WARNING_BPS = 4_000;

    public const DEFAULT_SERIOUS_BPS = 6_000;

    public static function fromShareBps(int $shareBps, int $warningBps, int $seriousBps): self
    {
        if ($shareBps >= $seriousBps) {
            return self::SERIOUS;
        }

        if ($shareBps >= $warningBps) {
            return self::WARNING;
        }

        return self::NORMAL;
    }

    public function isAlarming(): bool
    {
        return $this !== self::NORMAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'عادی',
            self::WARNING => 'هشدار تمرکز',
            self::SERIOUS => 'هشدار جدی تمرکز',
        };
    }
}
