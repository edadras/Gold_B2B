<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use App\Modules\Shared\Support\IntMath;
use JsonSerializable;
use Stringable;

/**
 * A rial amount. Signed, because ledger entries carry direction.
 *
 * Rate arithmetic uses the x100k scale defined in the docs: 0.15% is 150.
 */
final readonly class Rial implements JsonSerializable, Stringable
{
    public const RATE_SCALE = 100_000;

    private function __construct(public int $amount) {}

    public static function fromRial(int $amount): self
    {
        return new self($amount);
    }

    public static function fromString(string $input): self
    {
        return new self(NumericInput::toScaledInt($input, 0));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self(IntMath::add($this->amount, $other->amount));
    }

    public function minus(self $other): self
    {
        return new self(IntMath::sub($this->amount, $other->amount));
    }

    public function negate(): self
    {
        return new self(-$this->amount);
    }

    public function abs(): self
    {
        return new self(abs($this->amount));
    }

    /**
     * Apply a rate expressed in hundred-thousandths, rounding UP.
     *
     * Fees round up (F8): the direction is in the platform's favour and is
     * disclosed. Do not use this for amounts that must tie out by subtraction —
     * compute one side and derive the other (F9).
     */
    public function rateCeil(int $rateX100k): self
    {
        return new self(IntMath::mulDivCeil($this->amount, $rateX100k, self::RATE_SCALE));
    }

    /** Apply a rate rounding DOWN. */
    public function rateFloor(int $rateX100k): self
    {
        return new self(IntMath::mulDivFloor($this->amount, $rateX100k, self::RATE_SCALE));
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->amount >= $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        return $this->amount < $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function formatted(): string
    {
        return NumericInput::group((string) $this->amount);
    }

    public function withUnit(): string
    {
        return $this->formatted().' ریال';
    }

    public function jsonSerialize(): int
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }
}
