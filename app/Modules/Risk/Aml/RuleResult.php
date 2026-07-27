<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml;

use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Domain\RuleOutcome;

/** What one rule concluded. */
final readonly class RuleResult
{
    /** @param array<string, mixed> $context */
    private function __construct(
        public RuleOutcome $outcome,
        public ?FlagSeverity $severity = null,
        public ?string $summary = null,
        public array $context = [],
    ) {}

    public static function notApplicable(): self
    {
        return new self(RuleOutcome::NOT_APPLICABLE);
    }

    public static function pass(): self
    {
        return new self(RuleOutcome::PASS);
    }

    /** @param array<string, mixed> $context */
    public static function flag(FlagSeverity $severity, string $summary, array $context = []): self
    {
        return new self(RuleOutcome::FLAG, $severity, $summary, $context);
    }

    /** @param array<string, mixed> $context */
    public static function block(FlagSeverity $severity, string $summary, array $context = []): self
    {
        return new self(RuleOutcome::BLOCK, $severity, $summary, $context);
    }

    public function matched(): bool
    {
        return $this->outcome === RuleOutcome::FLAG || $this->outcome === RuleOutcome::BLOCK;
    }
}
