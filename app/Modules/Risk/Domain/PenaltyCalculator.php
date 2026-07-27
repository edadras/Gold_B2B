<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

use App\Modules\Risk\Contracts\PenaltyCalculatorInterface;
use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\Rial;

/**
 * F21 — late settlement penalty.
 *
 *   penalty = min(
 *       floor(amount × daily_rate_x100k × days_overdue / 100000),
 *       floor(amount × max_penalty_x100k / 100000)
 *   )
 *
 * Reference vector: 19,521,900,000 rial at 50 x100k for 3 days
 *   → 29,282,850, capped at 1,952,190,000.
 */
final class PenaltyCalculator implements PenaltyCalculatorInterface
{
    public function penalty(
        int $amountRial,
        int $daysOverdue,
        ?int $dailyRateX100k = null,
        ?int $maxPenaltyX100k = null,
    ): int {
        if ($amountRial <= 0 || $daysOverdue <= 0) {
            return 0;
        }

        $dailyRate = $dailyRateX100k ?? (int) config('goldb2b.settlement.penalty_daily_x100k', 50);

        $accrued = IntMath::mulDivFloor(
            $amountRial,
            IntMath::mul($dailyRate, $daysOverdue),
            Rial::RATE_SCALE,
        );

        return min($accrued, $this->cap($amountRial, $maxPenaltyX100k));
    }

    public function cap(int $amountRial, ?int $maxPenaltyX100k = null): int
    {
        if ($amountRial <= 0) {
            return 0;
        }

        $maxRate = $maxPenaltyX100k ?? (int) config('goldb2b.settlement.penalty_cap_x100k', 10_000);

        return IntMath::mulDivFloor($amountRial, $maxRate, Rial::RATE_SCALE);
    }

    /** Rial-typed convenience for callers that already hold value objects. */
    public function penaltyOn(Rial $amount, int $daysOverdue): Rial
    {
        return Rial::fromRial($this->penalty($amount->amount, $daysOverdue));
    }
}
