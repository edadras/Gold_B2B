<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use App\Modules\Shared\Support\IntMath;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Pure-gold-equivalent weight in milligrams.
 *
 * This is the unit the gold ledger is denominated in. Deliberately a separate
 * type from Weight so gross and fine can never be added together.
 *
 * Formula F1, docs/11-appendix/01-formulas.md.
 */
final readonly class FineWeight implements JsonSerializable, Stringable
{
    private function __construct(public int $milligrams)
    {
        if ($milligrams < 0) {
            throw new InvalidArgumentException('Fine weight cannot be negative');
        }
    }

    public static function fromMilligrams(int $mg): self
    {
        return new self($mg);
    }

    public static function fromGramsString(string $input): self
    {
        return new self(NumericInput::toScaledInt($input, 3));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * F1 — fine = floor(gross * purity / 10000).
     *
     * Rounds DOWN so the system never records gold it does not hold. The
     * discarded remainder is available via roundingRemainder() and must be
     * posted to the ROUNDING_DIFFERENCE system account.
     */
    public static function calculate(Weight $gross, Purity $purity): self
    {
        return new self(
            IntMath::mulDivFloor($gross->milligrams, $purity->value, Purity::SCALE)
        );
    }

    /**
     * The sub-milligram remainder discarded by calculate(), expressed in
     * ten-thousandths of a milligram.
     */
    public static function roundingRemainder(Weight $gross, Purity $purity): int
    {
        return IntMath::mulDivRemainder($gross->milligrams, $purity->value, Purity::SCALE);
    }

    /**
     * F2 — gross = ceil(fine * 10000 / purity).
     *
     * Rounds UP: the caller needs at least this much gross weight to deliver
     * the requested fine weight.
     */
    public function requiredGrossAt(Purity $purity): Weight
    {
        if ($purity->value === 0) {
            throw new InvalidArgumentException('Cannot derive gross weight at zero purity');
        }

        return Weight::fromMilligrams(
            IntMath::mulDivCeil($this->milligrams, Purity::SCALE, $purity->value)
        );
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

    public function min(self $other): self
    {
        return $this->milligrams <= $other->milligrams ? $this : $other;
    }

    public function grams(): string
    {
        return NumericInput::fromScaledInt($this->milligrams, 3);
    }

    public function gramsFormatted(): string
    {
        return NumericInput::group($this->grams());
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
