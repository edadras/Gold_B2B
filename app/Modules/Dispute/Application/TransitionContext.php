<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Domain\ActorType;

/**
 * Who is causing a transition, and why.
 *
 * §2.14 rule 1 requires every transition to record who, when and why, so this
 * is a required argument rather than an optional one — there is no way to move
 * a dispute without saying who moved it.
 */
final readonly class TransitionContext
{
    public function __construct(
        public ActorType $actorType,
        public ?int $actorUserId = null,
        public ?int $actorOrgId = null,
        public string $action = '',
        public ?string $message = null,
    ) {}

    /** §2.14 rule 4 — automatic transitions are attributed to the system. */
    public static function system(string $action, ?string $message = null): self
    {
        return new self(ActorType::SYSTEM, null, null, $action, $message);
    }

    public static function claimant(int $userId, int $orgId, string $action, ?string $message = null): self
    {
        return new self(ActorType::CLAIMANT, $userId, $orgId, $action, $message);
    }

    public static function respondent(int $userId, int $orgId, string $action, ?string $message = null): self
    {
        return new self(ActorType::RESPONDENT, $userId, $orgId, $action, $message);
    }

    public static function mediator(int $userId, string $action, ?string $message = null): self
    {
        return new self(ActorType::MEDIATOR, $userId, null, $action, $message);
    }

    public function withAction(string $action): self
    {
        return new self($this->actorType, $this->actorUserId, $this->actorOrgId, $action, $this->message);
    }
}
