<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** Human-readable assay certificate identifier, e.g. AS-00004421. */
final readonly class AssayCode implements Stringable
{
    public const DEFAULT_PREFIX = 'AS-';

    private function __construct(public string $value, public int $sequence) {}

    public static function forSequence(int $sequence): self
    {
        return new self(CodeFormat::format(self::prefix(), $sequence), $sequence);
    }

    public static function fromString(string $code): self
    {
        $prefix = self::prefix();

        if (! str_starts_with($code, $prefix)) {
            throw new InvalidArgumentException("Assay code must start with {$prefix}: {$code}");
        }

        $digits = substr($code, strlen($prefix));

        if ($digits === '' || ! ctype_digit($digits)) {
            throw new InvalidArgumentException("Assay code has a non-numeric sequence: {$code}");
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

    public static function placeholder(): string
    {
        return self::prefix().'TMP-'.bin2hex(random_bytes(6));
    }

    private static function prefix(): string
    {
        return (string) CodeFormat::setting('assay_prefix', self::DEFAULT_PREFIX);
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
