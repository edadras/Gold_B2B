<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * Human-readable lot identifier, e.g. GL-00001287.
 *
 * The numeric part is the row id, so a code and a primary key are trivially
 * convertible — the worked examples in docs/11-appendix rely on this
 * (lot id 1512 is GL-00001512).
 */
final readonly class LotCode implements Stringable
{
    public const DEFAULT_PREFIX = 'GL-';

    private function __construct(public string $value, public int $sequence) {}

    public static function forSequence(int $sequence): self
    {
        return new self(CodeFormat::format(self::prefix(), $sequence), $sequence);
    }

    public static function fromString(string $code): self
    {
        $prefix = self::prefix();

        if (! str_starts_with($code, $prefix)) {
            throw new InvalidArgumentException("Lot code must start with {$prefix}: {$code}");
        }

        $digits = substr($code, strlen($prefix));

        if ($digits === '' || ! ctype_digit($digits)) {
            throw new InvalidArgumentException("Lot code has a non-numeric sequence: {$code}");
        }

        return new self($code, (int) $digits);
    }

    public static function isValid(string $code): bool
    {
        try {
            self::fromString($code);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Placeholder used between INSERT and the UPDATE that stamps the real code.
     * gold_lots.lot_code is NOT NULL UNIQUE and the sequence is the row id, so
     * the code can only be derived after the insert.
     */
    public static function placeholder(): string
    {
        return self::prefix().'TMP-'.bin2hex(random_bytes(6));
    }

    private static function prefix(): string
    {
        return (string) CodeFormat::setting('lot_prefix', self::DEFAULT_PREFIX);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
