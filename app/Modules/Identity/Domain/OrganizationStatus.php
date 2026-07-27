<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Membership lifecycle of an organisation.
 *
 * The transition table is the one in docs/03-domain/01-identity-kyc.md §1.2,
 * cross-checked against the canonical listing in
 * docs/11-appendix/02-state-machines.md §2.2. Nothing may move an organisation
 * between states except App\Modules\Identity\Application\OrganizationStateMachine.
 *
 * CLOSING is the transitional state an organisation enters when closure was
 * requested but the hard preconditions for CLOSED (zero balances, no open
 * orders/settlements/disputes, no custody lots) are not yet satisfied. While
 * CLOSING only settlement of existing obligations is permitted.
 */
enum OrganizationStatus: string
{
    case PENDING = 'PENDING';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case INFO_REQUIRED = 'INFO_REQUIRED';
    case VERIFIED = 'VERIFIED';
    case ACTIVE = 'ACTIVE';
    case RESTRICTED = 'RESTRICTED';
    case SUSPENDED = 'SUSPENDED';
    case CLOSING = 'CLOSING';
    case CLOSED = 'CLOSED';
    case REJECTED = 'REJECTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * States reachable from this one in a single legal transition.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::UNDER_REVIEW],
            self::UNDER_REVIEW => [self::VERIFIED, self::INFO_REQUIRED, self::REJECTED],
            self::INFO_REQUIRED => [self::UNDER_REVIEW],
            self::VERIFIED => [self::ACTIVE],
            self::ACTIVE => [self::RESTRICTED, self::SUSPENDED, self::CLOSING],
            self::RESTRICTED => [self::ACTIVE, self::SUSPENDED, self::CLOSING],
            self::SUSPENDED => [self::ACTIVE, self::CLOSING],
            self::CLOSING => [self::CLOSED],
            self::REJECTED, self::CLOSED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** A final state can never be left. */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Only an ACTIVE organisation may open new positions. */
    public function canTrade(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * RESTRICTED members may reduce exposure (sell / settle) but not increase it.
     * CLOSING members may only settle what is already outstanding.
     */
    public function canSettle(): bool
    {
        return in_array($this, [self::ACTIVE, self::RESTRICTED, self::CLOSING], true);
    }

    /** Transitions that must cancel the member's resting orders. */
    public function cancelsOpenOrdersWhenEntered(): bool
    {
        return in_array($this, [self::RESTRICTED, self::SUSPENDED, self::CLOSING, self::CLOSED], true);
    }

    /** Whether reaching this state requires maker/checker approval. */
    public function requiresDualControlToEnter(): bool
    {
        return $this === self::CLOSED;
    }
}
