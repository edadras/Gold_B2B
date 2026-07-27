<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain;

enum DocumentStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case SUPERSEDED = 'SUPERSEDED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::VERIFIED, self::REJECTED, self::SUPERSEDED],
            self::VERIFIED => [self::EXPIRED, self::SUPERSEDED, self::REJECTED],
            self::REJECTED => [self::SUPERSEDED],
            self::EXPIRED => [self::SUPERSEDED],
            self::SUPERSEDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Counts towards dossier completeness. */
    public function countsAsProvided(): bool
    {
        return in_array($this, [self::PENDING, self::VERIFIED], true);
    }
}
