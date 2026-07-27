<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * One requested child of a split, expressed either as a gross weight (cut me a
 * 40 g piece) or as a fine weight (cut me enough to deliver 250 g of pure gold).
 *
 * The fine form is what Settlement uses; it resolves through formula F2,
 * rounding the gross weight UP so the child can definitely cover the promise.
 */
final readonly class SplitPart
{
    private function __construct(
        public ?int $grossMg,
        public ?int $fineMg,
        public ?string $serialNumber = null,
        public ?string $label = null,
    ) {}

    public static function byGross(Weight $gross, ?string $serialNumber = null, ?string $label = null): self
    {
        return new self(grossMg: $gross->milligrams, fineMg: null, serialNumber: $serialNumber, label: $label);
    }

    public static function byFine(FineWeight $fine, ?string $serialNumber = null, ?string $label = null): self
    {
        return new self(grossMg: null, fineMg: $fine->milligrams, serialNumber: $serialNumber, label: $label);
    }

    /** F2 — gross = ceil(fine * 10000 / purity) when the part is fine-denominated. */
    public function resolveGrossMg(Purity $purity): int
    {
        if ($this->grossMg !== null) {
            return $this->grossMg;
        }

        return FineWeight::fromMilligrams((int) $this->fineMg)
            ->requiredGrossAt($purity)
            ->milligrams;
    }

    public function isFineDenominated(): bool
    {
        return $this->fineMg !== null;
    }
}
