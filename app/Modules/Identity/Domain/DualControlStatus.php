<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Lifecycle of a maker/checker request.
 */
enum DualControlStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isFinal(): bool
    {
        return $this !== self::PENDING;
    }
}
