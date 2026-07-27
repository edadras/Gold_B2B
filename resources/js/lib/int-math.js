/**
 * Integer multiply-then-divide with an explicit rounding direction.
 *
 * Port of App\Modules\Shared\Support\IntMath. BigInt division truncates toward
 * zero, which is NOT floor for negative operands — `-7n / 2n` is `-3n`, while
 * floor(-3.5) is -4. Every function here corrects for that, because the ledger
 * carries signed amounts and a rounding rule that silently changes direction at
 * zero is the kind of bug that shows up months later as a reconciliation break.
 *
 * There is no overflow guard: BigInt has no ceiling. The PHP original needs one
 * because it is capped at 2^63.
 *
 * @module lib/int-math
 */

const toBig = (v) => (typeof v === 'bigint' ? v : BigInt(v));

/** floor(a * b / c). */
export function mulDivFloor(a, b, c) {
    const [x, y, z] = [toBig(a), toBig(b), toBig(c)];
    if (z === 0n) {
        throw new RangeError('Division by zero');
    }

    const product = x * y;
    const quotient = product / z;

    // Truncation and floor differ only when the division is inexact and the
    // result is negative.
    return product % z !== 0n && (product < 0n) !== (z < 0n) ? quotient - 1n : quotient;
}

/** ceil(a * b / c). */
export function mulDivCeil(a, b, c) {
    const [x, y, z] = [toBig(a), toBig(b), toBig(c)];
    if (z === 0n) {
        throw new RangeError('Division by zero');
    }

    const product = x * y;
    const quotient = product / z;

    return product % z !== 0n && (product < 0n) === (z < 0n) ? quotient + 1n : quotient;
}

/** The remainder discarded by mulDivFloor, in units of the divisor's scale. */
export function mulDivRemainder(a, b, c) {
    const [x, y, z] = [toBig(a), toBig(b), toBig(c)];
    if (z === 0n) {
        throw new RangeError('Division by zero');
    }

    const product = x * y;
    const remainder = product % z;

    return remainder !== 0n && (remainder < 0n) !== (z < 0n) ? remainder + z : remainder;
}
