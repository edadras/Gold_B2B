<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use App\Modules\Risk\Domain\LimitType;

/**
 * Non-throwing verdict from RiskGuard::evaluate(). Carries the first violated
 * rule — the checks run cheapest-first and stop, so exactly one is reported.
 */
final readonly class RiskDecision
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public bool $allowed,
        public ?string $reasonCode = null,
        public ?string $message = null,
        public ?LimitType $limitType = null,
        public ?int $requested = null,
        public ?int $limit = null,
        public array $details = [],
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }

    /** @param array<string, mixed> $details */
    public static function rejected(
        string $reasonCode,
        string $message,
        ?LimitType $limitType = null,
        ?int $requested = null,
        ?int $limit = null,
        array $details = [],
    ): self {
        return new self(false, $reasonCode, $message, $limitType, $requested, $limit, $details);
    }

    public function isRejected(): bool
    {
        return ! $this->allowed;
    }

    /** How much headroom is left, when the violation was a numeric ceiling. */
    public function headroom(): ?int
    {
        if ($this->requested === null || $this->limit === null) {
            return null;
        }

        return $this->limit - $this->requested;
    }
}
