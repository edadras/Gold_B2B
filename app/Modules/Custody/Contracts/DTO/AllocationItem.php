<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

use App\Modules\Shared\ValueObjects\FineWeight;

/** One line of an AllocationPlan: a lot, and how much of it is being used. */
final readonly class AllocationItem
{
    public function __construct(
        public int $lotId,
        public string $lotCode,
        public int $lotGrossMg,
        public int $lotFineMg,
        public int $purityX10,
        public int $useFineMg,
        public bool $whole,
    ) {}

    /** Fine weight that would stay with the owner if this lot is split. */
    public function remainderFineMg(): int
    {
        return $this->lotFineMg - $this->useFineMg;
    }

    public function use(): FineWeight
    {
        return FineWeight::fromMilligrams($this->useFineMg);
    }

    public function requiresSplit(): bool
    {
        return ! $this->whole;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lot_id' => $this->lotId,
            'lot_code' => $this->lotCode,
            'lot_gross_mg' => $this->lotGrossMg,
            'lot_fine_mg' => $this->lotFineMg,
            'purity_x10' => $this->purityX10,
            'use_fine_mg' => $this->useFineMg,
            'whole' => $this->whole,
        ];
    }
}
