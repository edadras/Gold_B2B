<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Identifier shared by every entry produced by one logical operation.
 *
 * The golden rule of docs/03-domain/03-ledger.md §3.3 is stated per group:
 * Σ(amount) over a group, per asset type, must be zero.
 */
final readonly class TransactionGroup implements JsonSerializable, Stringable
{
    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self((string) Str::uuid());
    }

    public static function fromString(string $value): self
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException("Transaction group must be a UUID, got: {$value}");
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
