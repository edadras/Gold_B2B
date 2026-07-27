<?php

declare(strict_types=1);

namespace App\Modules\Shared\Calculation;

use App\Modules\Shared\ValueObjects\Rial;

/**
 * Tax terms. The current regulatory position is zero for inter-dealer melted
 * gold, but the rate and base are configurable because that is a legal
 * question, not a technical one — see docs/10-compliance/01-regulatory.md §1.1(ز).
 */
final readonly class TaxTerms
{
    public const BASE_GROSS = 'GROSS';

    public const BASE_FEE = 'FEE';

    public function __construct(
        public int $rateX100k = 0,
        public string $base = self::BASE_GROSS,
    ) {}

    public static function none(): self
    {
        return new self(0);
    }

    /** Rounds UP, matching the fee convention (F8). */
    public function applyTo(Rial $gross, Rial $fee): Rial
    {
        if ($this->rateX100k === 0) {
            return Rial::zero();
        }

        $base = $this->base === self::BASE_FEE ? $fee : $gross;

        return $base->rateCeil($this->rateX100k);
    }
}
