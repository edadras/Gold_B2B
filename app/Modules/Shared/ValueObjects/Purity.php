<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Gold purity in ten-thousandths, i.e. market purity 995 is stored as 9950.
 *
 * The extra decade of precision exists because the market occasionally quotes
 * fractional purity (995.5). See ADR-013.
 */
final readonly class Purity implements JsonSerializable, Stringable
{
    public const MIN = 0;

    public const MAX = 10_000;

    public const SCALE = 10_000;

    private function __construct(public int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidArgumentException("Purity {$value} is outside 0..10000");
        }
    }

    /** Raw storage value (0..10000). */
    public static function fromScaled(int $value): self
    {
        return new self($value);
    }

    /** From conventional market purity: 750, 995, 999. */
    public static function fromPpt(int $ppt): self
    {
        return new self($ppt * 10);
    }

    /** From user input that may carry one decimal: "995.5". */
    public static function fromString(string $input): self
    {
        return new self(NumericInput::toScaledInt($input, 1));
    }

    public static function pure(): self
    {
        return new self(self::MAX);
    }

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** Conventional display: "995" or "995.5". */
    public function toPpt(): string
    {
        if ($this->value % 10 === 0) {
            return (string) intdiv($this->value, 10);
        }

        return NumericInput::fromScaledInt($this->value, 1);
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->toPpt();
    }
}
