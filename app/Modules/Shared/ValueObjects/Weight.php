<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use App\Modules\Shared\Support\IntMath;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Gross weight, always stored in milligrams.
 *
 * Distinct from FineWeight on purpose: mixing gross and fine weight is one of
 * the easiest ways to corrupt a gold ledger, so the type system refuses it.
 *
 * See docs/00-overview/02-glossary.md §2.6 and ADR-002.
 */
final readonly class Weight implements JsonSerializable, Stringable
{
    public const MG_PER_GRAM = 1_000;

    /** 4.6083 g, stored scaled by 10 to stay integral. */
    public const MESGHAL_MG_X10 = 46_083;

    /** 31.1034768 g, stored scaled by 10^4 to stay integral. */
    public const OUNCE_MG_X10000 = 311_034_768;

    private function __construct(public int $milligrams)
    {
        if ($milligrams < 0) {
            throw new InvalidArgumentException('Weight cannot be negative');
        }
    }

    public static function fromMilligrams(int $mg): self
    {
        return new self($mg);
    }

    /**
     * Parse a user-entered gram amount without going through float.
     *
     * Accepts "250", "250.5", "1,247.320", and Persian/Arabic digits.
     */
    public static function fromGramsString(string $input): self
    {
        return new self(NumericInput::toScaledInt($input, 3));
    }

    public static function fromMesghalString(string $input): self
    {
        $scaled = NumericInput::toScaledInt($input, 4); // mesghal x 10^4

        return new self(IntMath::mulDivFloor($scaled, self::MESGHAL_MG_X10, 100_000));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self(IntMath::add($this->milligrams, $other->milligrams));
    }

    public function minus(self $other): self
    {
        return new self(IntMath::sub($this->milligrams, $other->milligrams));
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->milligrams > $other->milligrams;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->milligrams >= $other->milligrams;
    }

    public function isLessThan(self $other): bool
    {
        return $this->milligrams < $other->milligrams;
    }

    public function equals(self $other): bool
    {
        return $this->milligrams === $other->milligrams;
    }

    public function isZero(): bool
    {
        return $this->milligrams === 0;
    }

    /** Exact decimal string in grams, e.g. "1247.320". Display only. */
    public function grams(): string
    {
        return NumericInput::fromScaledInt($this->milligrams, 3);
    }

    /** Grouped for UI, e.g. "1,247.320". Display only. */
    public function gramsFormatted(): string
    {
        return NumericInput::group($this->grams());
    }

    public function mesghal(): string
    {
        $scaled = IntMath::mulDivFloor($this->milligrams, 100_000, self::MESGHAL_MG_X10);

        return NumericInput::fromScaledInt($scaled, 4);
    }

    public function jsonSerialize(): int
    {
        return $this->milligrams;
    }

    public function __toString(): string
    {
        return $this->grams();
    }
}
