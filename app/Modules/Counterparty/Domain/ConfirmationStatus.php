<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Domain;

use App\Modules\Shared\Exceptions\InvalidStateTransitionException;

/**
 * Lifecycle of a balance confirmation request.
 *
 * DISPUTED is deliberately not terminal-with-escalation: the two sides may
 * still settle it between themselves, and only an explicit hand-off creates a
 * case in the Dispute module (docs §10.4).
 */
enum ConfirmationStatus: string
{
    case PENDING = 'PENDING';
    case AGREED = 'AGREED';
    case DISPUTED = 'DISPUTED';
    case ESCALATED = 'ESCALATED';
    case CANCELLED = 'CANCELLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isOpen(): bool
    {
        return $this === self::PENDING || $this === self::DISPUTED;
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PENDING => [self::AGREED, self::DISPUTED, self::CANCELLED],
            self::DISPUTED => [self::AGREED, self::ESCALATED, self::CANCELLED],
            self::AGREED, self::ESCALATED, self::CANCELLED => [],
        };
    }

    /** @throws InvalidStateTransitionException */
    public function assertCanTransitionTo(self $next): void
    {
        if (! in_array($next, $this->allowedNext(), true)) {
            throw new InvalidStateTransitionException(
                'balance_confirmation',
                $this->value,
                $next->value,
            );
        }
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'در انتظار پاسخ',
            self::AGREED => 'تأیید شده',
            self::DISPUTED => 'مغایرت',
            self::ESCALATED => 'ارجاع به اختلاف',
            self::CANCELLED => 'لغو شده',
        };
    }
}
