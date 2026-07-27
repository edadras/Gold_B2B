<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml;

use App\Modules\Risk\Domain\RuleOutcome;

/** Aggregate verdict of one pass over the active rule set. */
final readonly class AmlEvaluation
{
    /**
     * @param  array<string, RuleResult>  $results  rule code => result
     * @param  list<int>  $flagIds
     * @param  list<string>  $blockingRuleCodes
     */
    public function __construct(
        public RuleOutcome $outcome,
        public array $results = [],
        public array $flagIds = [],
        public array $blockingRuleCodes = [],
    ) {}

    public static function pass(): self
    {
        return new self(RuleOutcome::PASS);
    }

    public function isBlocked(): bool
    {
        return $this->outcome === RuleOutcome::BLOCK;
    }

    public function raisedFlags(): bool
    {
        return $this->flagIds !== [];
    }

    /** @return list<string> */
    public function matchedRuleCodes(): array
    {
        $codes = [];

        foreach ($this->results as $code => $result) {
            if ($result->matched()) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
