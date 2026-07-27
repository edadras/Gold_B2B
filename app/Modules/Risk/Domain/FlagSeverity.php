<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** docs/03-domain/12-aml-compliance.md §12.5. */
enum FlagSeverity: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    public function rank(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** Review SLA, §12.5. Null assignment deadline means "immediately". */
    public function assignmentDeadlineHours(): ?int
    {
        return match ($this) {
            self::CRITICAL => null,
            self::HIGH => 1,
            self::MEDIUM => 4,
            self::LOW => 24,
        };
    }

    public function decisionDeadlineHours(): int
    {
        return match ($this) {
            self::CRITICAL => 2,
            self::HIGH => 24,
            self::MEDIUM => 72,
            self::LOW => 168,
        };
    }

    /** Credit-score penalty of §11.2 for a high-severity flag. */
    public function creditScorePenalty(): int
    {
        return match ($this) {
            self::CRITICAL, self::HIGH => 150,
            self::MEDIUM => 40,
            self::LOW => 0,
        };
    }
}
