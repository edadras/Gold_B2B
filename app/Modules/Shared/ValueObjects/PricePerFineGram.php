<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use App\Modules\Shared\Support\IntMath;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Rial per gram of pure gold.
 *
 * The whole market quotes in fine grams so purities are directly comparable —
 * see docs/03-domain/04-trading.md §4.1.
 */
final readonly class PricePerFineGram implements JsonSerializable
{
    public const BPS_SCALE = 10_000;

    private function __construct(public int $rial)
    {
        if ($rial < 0) {
            throw new InvalidArgumentException('Price cannot be negative');
        }
    }

    public static function fromRial(int $rial): self
    {
        return new self($rial);
    }

    public static function fromString(string $input): self
    {
        return new self(NumericInput::toScaledInt($input, 0));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * F5 — gross = floor(fine_mg * price / 1000).
     */
    public function valueOf(FineWeight $weight): Rial
    {
        return Rial::fromRial(
            IntMath::mulDivFloor($weight->milligrams, $this->rial, Weight::MG_PER_GRAM)
        );
    }

    /** Apply a slippage allowance upward, rounding up (worst case for a buyer). */
    public function worseForBuyer(int $slippageBps): self
    {
        return new self(
            IntMath::mulDivCeil($this->rial, self::BPS_SCALE + $slippageBps, self::BPS_SCALE)
        );
    }

    /** Apply a slippage allowance downward, rounding down (worst case for a seller). */
    public function worseForSeller(int $slippageBps): self
    {
        return new self(
            IntMath::mulDivFloor($this->rial, self::BPS_SCALE - $slippageBps, self::BPS_SCALE)
        );
    }

    /**
     * Deviation from a reference price in basis points, always non-negative.
     * Used by the fat-finger guard and the circuit breaker (F24).
     */
    public function deviationBpsFrom(self $reference): int
    {
        if ($reference->rial === 0) {
            throw new InvalidArgumentException('Cannot compute deviation from a zero reference');
        }

        $delta = abs($this->rial - $reference->rial);

        return IntMath::mulDivFloor($delta, self::BPS_SCALE, $reference->rial);
    }

    public function isMultipleOf(int $tickSize): bool
    {
        return $tickSize > 0 && $this->rial % $tickSize === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->rial > $other->rial;
    }

    public function isLessThan(self $other): bool
    {
        return $this->rial < $other->rial;
    }

    public function equals(self $other): bool
    {
        return $this->rial === $other->rial;
    }

    public function formatted(): string
    {
        return NumericInput::group((string) $this->rial);
    }

    public function jsonSerialize(): int
    {
        return $this->rial;
    }
}
