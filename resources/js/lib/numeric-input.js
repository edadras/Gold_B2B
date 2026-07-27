/**
 * Decimal string <-> scaled integer conversion that never touches float.
 *
 * Port of App\Modules\Shared\ValueObjects\NumericInput. The semantics are
 * copied deliberately, including the parts that look fussy:
 *
 *   · extra decimal places are TRUNCATED, never rounded, so a trader cannot
 *     conjure weight by typing more digits than the scale holds;
 *   · Persian and Arabic digits, the Arabic decimal separator and both Arabic
 *     thousands separators are normalised before parsing, because a member
 *     typing on a Persian keyboard produces ۱۲۳ and ٫ and the field must accept
 *     them; and
 *   · the result is a BigInt. JavaScript numbers lose integers above 2^53, and
 *     a rial amount like 19,551,182,850 is already within a factor of a
 *     thousand of that ceiling — a single multiplication overflows it.
 *
 * @module lib/numeric-input
 */

const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

/** Normalise Persian/Arabic digits and separators to plain ASCII. */
export function normalizeDigits(input) {
    let output = String(input);

    for (let i = 0; i < 10; i++) {
        output = output.split(PERSIAN_DIGITS[i]).join(String(i));
        output = output.split(ARABIC_DIGITS[i]).join(String(i));
    }

    return output
        .split('٫').join('.')
        .split('،').join(',')
        .split('٬').join(',');
}

/**
 * "1,247.32" with scale 3 -> 1247320n
 *
 * @param {string} input
 * @param {number} scale
 * @returns {bigint}
 */
export function toScaledInt(input, scale) {
    let normalized = normalizeDigits(input).trim();
    normalized = normalized.split(',').join('').split(' ').join('').split('_').join('');

    if (normalized === '') {
        throw new RangeError('Numeric input is empty');
    }

    let negative = false;
    if (normalized.startsWith('-')) {
        negative = true;
        normalized = normalized.slice(1);
    } else if (normalized.startsWith('+')) {
        normalized = normalized.slice(1);
    }

    if (!/^\d*(?:\.\d*)?$/.test(normalized) || normalized === '' || normalized === '.') {
        throw new RangeError(`Invalid numeric input: ${input}`);
    }

    const dot = normalized.indexOf('.');
    let whole = dot === -1 ? normalized : normalized.slice(0, dot);
    let fraction = dot === -1 ? '' : normalized.slice(dot + 1);

    whole = whole === '' ? '0' : whole;
    fraction = fraction.padEnd(scale, '0').slice(0, scale);

    const value = BigInt(whole + fraction);

    return negative ? -value : value;
}

/**
 * 1247320n with scale 3 -> "1247.320"
 *
 * @param {bigint|number|string} value
 * @param {number} scale
 * @returns {string}
 */
export function fromScaledInt(value, scale) {
    const big = typeof value === 'bigint' ? value : BigInt(value);
    const negative = big < 0n;
    let abs = (negative ? -big : big).toString();

    if (scale === 0) {
        return (negative ? '-' : '') + abs;
    }

    abs = abs.padStart(scale + 1, '0');

    return `${negative ? '-' : ''}${abs.slice(0, -scale)}.${abs.slice(-scale)}`;
}

/** Insert thousands separators into the integer part of a decimal string. */
export function group(decimal) {
    let text = String(decimal);
    const negative = text.startsWith('-');
    if (negative) {
        text = text.slice(1);
    }

    const dot = text.indexOf('.');
    const whole = dot === -1 ? text : text.slice(0, dot);
    const fraction = dot === -1 ? null : text.slice(dot + 1);

    // Grouped by hand rather than with toLocaleString(): that goes through a
    // Number and would round anything past 2^53.
    let grouped = '';
    for (let i = 0; i < whole.length; i++) {
        if (i > 0 && (whole.length - i) % 3 === 0) {
            grouped += ',';
        }
        grouped += whole[i];
    }

    return (negative ? '-' : '') + grouped + (fraction === null ? '' : `.${fraction}`);
}

/** ASCII digits to Persian ones. Leaves separators and letters alone. */
export function toPersianDigits(input) {
    let output = String(input);
    for (let i = 0; i < 10; i++) {
        output = output.split(String(i)).join(PERSIAN_DIGITS[i]);
    }
    return output;
}

/** Persian digits back to ASCII — the inverse, for round-tripping a field. */
export function toAsciiDigits(input) {
    return normalizeDigits(input);
}
