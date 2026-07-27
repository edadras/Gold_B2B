<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Exceptions\ArithmeticOverflowException;
use DivisionByZeroError;

/**
 * Overflow-safe integer arithmetic for financial values.
 *
 * Every financial quantity in this system is a 64-bit integer (milligrams for
 * weight, rial for money). Intermediate products can exceed PHP_INT_MAX, so all
 * multiply-then-divide operations route through bcmath and are checked before
 * being handed back as int.
 *
 * See docs/11-appendix/01-formulas.md and ADR-002.
 */
final class IntMath
{
    private function __construct() {}

    /**
     * floor(a * b / c) with an arbitrary-precision intermediate product.
     *
     * @throws ArithmeticOverflowException when the result does not fit in a 64-bit int
     */
    public static function mulDivFloor(int $a, int $b, int $c): int
    {
        if ($c === 0) {
            throw new DivisionByZeroError('Division by zero in mulDivFloor');
        }

        $product = bcmul((string) $a, (string) $b, 0);
        $result = self::bcDivFloor($product, (string) $c);

        return self::toInt($result);
    }

    /**
     * ceil(a * b / c) with an arbitrary-precision intermediate product.
     *
     * @throws ArithmeticOverflowException when the result does not fit in a 64-bit int
     */
    public static function mulDivCeil(int $a, int $b, int $c): int
    {
        if ($c === 0) {
            throw new DivisionByZeroError('Division by zero in mulDivCeil');
        }

        $product = bcmul((string) $a, (string) $b, 0);
        $result = self::bcDivCeil($product, (string) $c);

        return self::toInt($result);
    }

    /**
     * The remainder discarded by mulDivFloor, expressed in units of the divisor's
     * numerator scale: a*b - floor(a*b/c)*c. Used to route rounding dust into the
     * ROUNDING_DIFFERENCE system account so mass conservation still holds.
     */
    public static function mulDivRemainder(int $a, int $b, int $c): int
    {
        if ($c === 0) {
            throw new DivisionByZeroError('Division by zero in mulDivRemainder');
        }

        $product = bcmul((string) $a, (string) $b, 0);
        $quotient = self::bcDivFloor($product, (string) $c);
        $remainder = bcsub($product, bcmul($quotient, (string) $c, 0), 0);

        return self::toInt($remainder);
    }

    /** Addition with overflow detection. */
    public static function add(int $a, int $b): int
    {
        return self::toInt(bcadd((string) $a, (string) $b, 0));
    }

    /** Subtraction with overflow detection. */
    public static function sub(int $a, int $b): int
    {
        return self::toInt(bcsub((string) $a, (string) $b, 0));
    }

    /** Multiplication with overflow detection. */
    public static function mul(int $a, int $b): int
    {
        return self::toInt(bcmul((string) $a, (string) $b, 0));
    }

    /**
     * Sum of a list with overflow detection.
     *
     * @param  iterable<int>  $values
     */
    public static function sum(iterable $values): int
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, (string) $value, 0);
        }

        return self::toInt($total);
    }

    /**
     * bcdiv truncates toward zero; we need a true floor for negative numerators
     * because ledger entries are signed.
     */
    private static function bcDivFloor(string $numerator, string $denominator): string
    {
        $quotient = bcdiv($numerator, $denominator, 0);

        // Truncation equals floor unless there is a remainder and signs differ.
        $remainder = bcsub($numerator, bcmul($quotient, $denominator, 0), 0);

        if (bccomp($remainder, '0', 0) !== 0 && self::signsDiffer($numerator, $denominator)) {
            $quotient = bcsub($quotient, '1', 0);
        }

        return $quotient;
    }

    private static function bcDivCeil(string $numerator, string $denominator): string
    {
        $quotient = bcdiv($numerator, $denominator, 0);
        $remainder = bcsub($numerator, bcmul($quotient, $denominator, 0), 0);

        if (bccomp($remainder, '0', 0) !== 0 && ! self::signsDiffer($numerator, $denominator)) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return $quotient;
    }

    private static function signsDiffer(string $a, string $b): bool
    {
        $aNegative = bccomp($a, '0', 0) < 0;
        $bNegative = bccomp($b, '0', 0) < 0;

        return $aNegative !== $bNegative;
    }

    /** @throws ArithmeticOverflowException */
    private static function toInt(string $value): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) > 0 || bccomp($value, (string) PHP_INT_MIN, 0) < 0) {
            throw new ArithmeticOverflowException($value);
        }

        return (int) $value;
    }
}
