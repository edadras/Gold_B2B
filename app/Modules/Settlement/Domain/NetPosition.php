<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

use InvalidArgumentException;

/**
 * One participant's multilateral position — F13 of
 * docs/11-appendix/01-formulas.md §1.6.
 *
 *   net_position(i) = Σ obligations(*→i) − Σ obligations(i→*)
 *
 * Positive means creditor (the batch owes them), negative means debtor. This
 * is invariant N2, and it is asserted in the constructor rather than trusted.
 */
final readonly class NetPosition
{
    public function __construct(
        public int $organizationId,
        public int $grossIn,
        public int $grossOut,
        public int $net,
        public int $obligationCount = 0,
    ) {
        if ($net !== $grossIn - $grossOut) {
            throw new InvalidArgumentException(sprintf(
                'N2 violated for organisation %d: %d != %d - %d',
                $organizationId,
                $net,
                $grossIn,
                $grossOut,
            ));
        }
    }

    public static function of(int $organizationId, int $grossIn, int $grossOut, int $count = 0): self
    {
        return new self($organizationId, $grossIn, $grossOut, $grossIn - $grossOut, $count);
    }

    public function isCreditor(): bool
    {
        return $this->net > 0;
    }

    public function isDebtor(): bool
    {
        return $this->net < 0;
    }

    /** Perfectly matched: receives exactly what it delivers, no transfer needed. */
    public function isFlat(): bool
    {
        return $this->net === 0;
    }

    /** Unsigned size of the single transfer this participant makes or receives. */
    public function magnitude(): int
    {
        return abs($this->net);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'gross_in' => $this->grossIn,
            'gross_out' => $this->grossOut,
            'net_position' => $this->net,
            'obligation_count' => $this->obligationCount,
        ];
    }
}
