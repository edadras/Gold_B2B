<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

/**
 * The who / when / why every settlement transition has to record
 * (docs/11-appendix/02-state-machines.md §2.14 rule 1).
 *
 * $applyLedgerEffects is the one escape hatch. Two operations post their ledger
 * entries themselves, before the transition, because the posting cannot be
 * derived from a single settlement row:
 *
 *   - netting execution writes ONE transaction_group covering the whole batch
 *     (§5.6, "ثبت در دفتر (یک transaction_group بزرگ)"), so the per-settlement
 *     side effect would double-post it;
 *   - reversal needs the dual-control identities and posts the mirror image of
 *     the original group (§5.8).
 *
 * Both pass applyLedgerEffects: false and record their transaction_group on the
 * event row instead. Every other transition lets the state machine do it, which
 * keeps the §5.2 table honest.
 */
final readonly class TransitionContext
{
    /** @param ?array<string, mixed> $metadata */
    public function __construct(
        public ActorType $actorType = ActorType::SYSTEM,
        public ?int $actorUserId = null,
        public ?string $reason = null,
        public ?array $metadata = null,
        public bool $applyLedgerEffects = true,
        public ?string $transactionGroup = null,
    ) {}

    public static function system(?string $reason = null): self
    {
        return new self(ActorType::SYSTEM, null, $reason);
    }

    /** @param ?array<string, mixed> $metadata */
    public static function user(int $userId, ?string $reason = null, ?array $metadata = null): self
    {
        return new self(ActorType::USER, $userId, $reason, $metadata);
    }

    /** @param ?array<string, mixed> $metadata */
    public static function staff(int $userId, ?string $reason = null, ?array $metadata = null): self
    {
        return new self(ActorType::PLATFORM_STAFF, $userId, $reason, $metadata);
    }

    /** The service has already posted the ledger group; just record it. */
    public function withLedgerAlreadyPosted(?string $transactionGroup = null): self
    {
        return new self(
            $this->actorType,
            $this->actorUserId,
            $this->reason,
            $this->metadata,
            false,
            $transactionGroup ?? $this->transactionGroup,
        );
    }

    /** @param array<string, mixed> $extra */
    public function withMetadata(array $extra): self
    {
        return new self(
            $this->actorType,
            $this->actorUserId,
            $this->reason,
            array_merge($this->metadata ?? [], $extra),
            $this->applyLedgerEffects,
            $this->transactionGroup,
        );
    }

    public function withReason(string $reason): self
    {
        return new self(
            $this->actorType,
            $this->actorUserId,
            $reason,
            $this->metadata,
            $this->applyLedgerEffects,
            $this->transactionGroup,
        );
    }
}
