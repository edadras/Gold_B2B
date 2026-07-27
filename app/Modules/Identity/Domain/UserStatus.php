<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Lifecycle of a human login (docs/04-data/02-schema-mysql.md §2.1 `users`).
 */
enum UserStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case DISABLED = 'DISABLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::ACTIVE, self::DISABLED],
            self::ACTIVE => [self::SUSPENDED, self::DISABLED],
            self::SUSPENDED => [self::ACTIVE, self::DISABLED],
            self::DISABLED => [],
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

    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }
}
