<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/**
 * Result of F19: the total plus the per-component breakdown, because a member
 * who is told "your score is 640" will always ask which part cost them.
 */
final readonly class CreditScore
{
    public const MAX = 1_000;

    /** @param array<string, int> $breakdown component key => points awarded */
    public function __construct(
        public int $score,
        public array $breakdown,
    ) {}

    public function riskLevel(): RiskLevel
    {
        return RiskLevel::fromCreditScore($this->score);
    }

    public function pointsFor(string $component): int
    {
        return $this->breakdown[$component] ?? 0;
    }
}
