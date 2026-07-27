<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/**
 * F20 bands — docs/11-appendix/01-formulas.md and §11.7.
 *
 *   ≥ 15000 bps  healthy
 *   12000–14999  warning
 *   11000–11999  margin call, four hours to top up
 *   < 11000      stop new trades and liquidate partially
 */
enum CoverageStatus: string
{
    case HEALTHY = 'HEALTHY';
    case WARNING = 'WARNING';
    case MARGIN_CALL = 'MARGIN_CALL';
    case PARTIAL_LIQUIDATION = 'PARTIAL_LIQUIDATION';

    public static function fromRatioBps(int $ratioBps): self
    {
        return match (true) {
            $ratioBps >= 15_000 => self::HEALTHY,
            $ratioBps >= 12_000 => self::WARNING,
            $ratioBps >= 11_000 => self::MARGIN_CALL,
            default => self::PARTIAL_LIQUIDATION,
        };
    }

    public function blocksNewTrades(): bool
    {
        return $this === self::PARTIAL_LIQUIDATION;
    }

    public function requiresMarginCall(): bool
    {
        return $this === self::MARGIN_CALL || $this === self::PARTIAL_LIQUIDATION;
    }

    public function topUpDeadlineHours(): ?int
    {
        return $this === self::MARGIN_CALL ? 4 : null;
    }
}
