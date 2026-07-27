<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

/**
 * F21 — late settlement penalty. Settlement depends on this interface so the
 * rate policy lives in one place.
 */
interface PenaltyCalculatorInterface
{
    /**
     * penalty = min(
     *     floor(amount × daily_rate_x100k × days_overdue / 100000),
     *     floor(amount × max_penalty_x100k / 100000)
     * )
     *
     * Passing null for either rate uses the platform defaults from
     * config('goldb2b.settlement').
     */
    public function penalty(
        int $amountRial,
        int $daysOverdue,
        ?int $dailyRateX100k = null,
        ?int $maxPenaltyX100k = null,
    ): int;

    /** The cap on its own, for showing a member their worst case. */
    public function cap(int $amountRial, ?int $maxPenaltyX100k = null): int;
}
