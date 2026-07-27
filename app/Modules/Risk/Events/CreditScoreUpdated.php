<?php

declare(strict_types=1);

namespace App\Modules\Risk\Events;

/** F19 recomputed or adjusted by an immediate-penalty event (§11.2). */
final readonly class CreditScoreUpdated
{
    /** @param array<string, int> $breakdown */
    public function __construct(
        public int $organizationId,
        public int $previousScore,
        public int $newScore,
        public string $reason,
        public array $breakdown = [],
    ) {}

    public function delta(): int
    {
        return $this->newScore - $this->previousScore;
    }
}
