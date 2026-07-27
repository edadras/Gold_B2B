<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use JsonSerializable;

/**
 * What one deadline sweep did.
 *
 * Failures are carried rather than thrown: the command needs to report a
 * non-zero exit status while still having escalated everything it could.
 */
final readonly class DeadlineSweepResult implements JsonSerializable
{
    /** @param array<int, string> $failures */
    public function __construct(
        public int $noReplyEscalated,
        public int $negotiationEscalated,
        public array $failures = [],
    ) {}

    public function total(): int
    {
        return $this->noReplyEscalated + $this->negotiationEscalated;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'no_reply_escalated' => $this->noReplyEscalated,
            'negotiation_escalated' => $this->negotiationEscalated,
            'total' => $this->total(),
            'failures' => $this->failures,
        ];
    }
}
