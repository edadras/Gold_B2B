<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain;

/**
 * Who caused a lot status transition and why.
 * Mirrors the pattern in docs/11-appendix/02-state-machines.md §2.1.
 */
final readonly class TransitionContext
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public ?int $actorUserId = null,
        public string $actorType = 'USER',
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public array $metadata = [],
    ) {}

    public static function system(string $reason, ?string $referenceType = null, ?int $referenceId = null): self
    {
        return new self(
            actorUserId: null,
            actorType: 'SYSTEM',
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public static function user(int $userId, ?string $reason = null): self
    {
        return new self(actorUserId: $userId, actorType: 'USER', reason: $reason);
    }

    public function forOperation(int $operationId, ?string $reason = null): self
    {
        return new self(
            actorUserId: $this->actorUserId,
            actorType: $this->actorType,
            reason: $reason ?? $this->reason,
            referenceType: 'custody_operation',
            referenceId: $operationId,
            metadata: $this->metadata,
        );
    }
}
