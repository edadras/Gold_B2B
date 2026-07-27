<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Typed handle on an immutable ledger row.
 *
 * Other modules store these (orders keep reservation_entry_id, settlements keep
 * the lock entries) and hand them back to release() or reverse().
 */
final readonly class LedgerEntryId implements JsonSerializable, Stringable
{
    private function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("Ledger entry id must be positive, got: {$value}");
        }
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
