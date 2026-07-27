<?php

declare(strict_types=1);

namespace App\Modules\Shared\Calculation;

use App\Modules\Shared\ValueObjects\Rial;

/**
 * Fee terms for one side of a trade, in hundred-thousandths (0.15% -> 150).
 */
final readonly class FeeTerms
{
    public function __construct(
        public int $rateX100k,
        public ?Rial $minAmount = null,
        public ?Rial $maxAmount = null,
    ) {}

    public static function none(): self
    {
        return new self(0);
    }

    public static function rate(int $rateX100k): self
    {
        return new self($rateX100k);
    }

    /** F8 — fee = ceil(gross * rate / 100000), then clamped to min/max. */
    public function applyTo(Rial $gross): Rial
    {
        $fee = $gross->rateCeil($this->rateX100k);

        if ($this->minAmount !== null && $fee->isLessThan($this->minAmount)) {
            return $this->minAmount;
        }

        if ($this->maxAmount !== null && $fee->isGreaterThan($this->maxAmount)) {
            return $this->maxAmount;
        }

        return $fee;
    }
}
