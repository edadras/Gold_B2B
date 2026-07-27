<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * NettingBatch state machine — docs/11-appendix/02-state-machines.md §2.9.
 *
 * PROPOSED → ACCEPTING on the first acceptance, ACCEPTING → EXECUTING only
 * when everybody has accepted (invariant N6, ADR-009). A single rejection, or
 * a missed acceptance deadline, cancels the whole batch and the underlying
 * settlements fall back to gross settlement.
 *
 * FAILED is recoverable: execution can be retried after the operator fixes
 * whatever went wrong, which is why FAILED → EXECUTING exists.
 */
enum NettingBatchStatus: string
{
    case PROPOSED = 'PROPOSED';
    case ACCEPTING = 'ACCEPTING';
    case EXECUTING = 'EXECUTING';
    case EXECUTED = 'EXECUTED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PROPOSED => [self::ACCEPTING, self::CANCELLED],
            self::ACCEPTING => [self::EXECUTING, self::CANCELLED],
            self::EXECUTING => [self::EXECUTED, self::FAILED],
            self::FAILED => [self::EXECUTING, self::CANCELLED],
            self::EXECUTED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** A batch that still holds settlements hostage to its outcome. */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /** Batches that block a settlement from joining another batch (invariant N5). */
    public function blocksReNetting(): bool
    {
        return $this !== self::CANCELLED;
    }

    /** Participants may still answer the proposal. */
    public function acceptsResponses(): bool
    {
        return $this === self::PROPOSED || $this === self::ACCEPTING;
    }
}
