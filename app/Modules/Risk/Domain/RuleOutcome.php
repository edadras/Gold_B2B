<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** PASS / FLAG / BLOCK, plus the internal "this rule does not apply". */
enum RuleOutcome: string
{
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case PASS = 'PASS';
    case FLAG = 'FLAG';
    case BLOCK = 'BLOCK';

    public function rank(): int
    {
        return match ($this) {
            self::NOT_APPLICABLE => 0,
            self::PASS => 1,
            self::FLAG => 2,
            self::BLOCK => 3,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}
