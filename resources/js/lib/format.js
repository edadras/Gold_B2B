/**
 * Display formatting — the client half of App\Modules\Shared\Http\Support\Display.
 *
 * THE BIDIRECTIONAL RULE
 * ----------------------
 * The panel is `dir="rtl"`. A number rendered inside an RTL context without an
 * explicit direction gets reordered by the Unicode bidi algorithm: "1,247.320"
 * can come out as "320.1,247", and a leading minus sign jumps to the far side
 * of the value. Every helper here therefore returns a plain ASCII string that
 * the calling component MUST place inside `dir="ltr"` — that is what
 * `<MoneyCell>`, `<WeightCell>` and `.num` in the stylesheet do, and it is why
 * they exist as components rather than as string interpolation.
 *
 * Persian digits are a separate decision from direction. They are used for
 * prose and headline figures, and NOT inside tables: Persian digits are not
 * tabular in most fonts, so columns of them do not align even with
 * `font-variant-numeric: tabular-nums`.
 *
 * @module lib/format
 */

import { fromScaledInt, group, toPersianDigits } from './numeric-input.js';

const EM_DASH = '—';

const isBlank = (v) => v === null || v === undefined || v === '';

/**
 * Milligrams to grams: 1047320 -> "1,047.320".
 *
 * Accepts a Number because that is what the API sends. Milligram counts stay
 * far below 2^53 (that is 9 billion tonnes of gold), so the integer is exact;
 * the conversion to a string happens before any arithmetic that could overflow.
 */
export function grams(milligrams, { unit = false, persian = false } = {}) {
    if (isBlank(milligrams)) {
        return EM_DASH;
    }

    const text = group(fromScaledInt(BigInt(milligrams), 3));
    const out = unit ? `${text} گرم` : text;

    return persian ? toPersianDigits(out) : out;
}

/** Rial amounts: 78510000 -> "78,510,000". */
export function rial(amount, { unit = false, persian = false } = {}) {
    if (isBlank(amount)) {
        return EM_DASH;
    }

    const text = group(BigInt(amount).toString());
    const out = unit ? `${text} ریال` : text;

    return persian ? toPersianDigits(out) : out;
}

/** Rial rounded to a readable magnitude for the balance rail: "4.20 میلیارد". */
export function rialCompact(amount, { persian = false } = {}) {
    if (isBlank(amount)) {
        return EM_DASH;
    }

    const value = BigInt(amount);
    const negative = value < 0n;
    const abs = negative ? -value : value;
    const sign = negative ? '-' : '';

    // Scaled integer division, two decimals, no float anywhere.
    const scale = (divisor, suffix) => {
        const hundredths = (abs * 100n) / divisor;
        return `${sign}${fromScaledInt(hundredths, 2)} ${suffix}`;
    };

    let text;
    if (abs >= 1_000_000_000_000n) {
        text = scale(1_000_000_000_000n, 'همت');
    } else if (abs >= 1_000_000_000n) {
        text = scale(1_000_000_000n, 'میلیارد');
    } else if (abs >= 1_000_000n) {
        text = scale(1_000_000n, 'میلیون');
    } else {
        text = sign + group(abs.toString());
    }

    return persian ? toPersianDigits(text) : text;
}

/** Purity in ten-thousandths rendered per-mille: 9950 -> "995". */
export function purity(x10000, { persian = false } = {}) {
    if (isBlank(x10000)) {
        return EM_DASH;
    }

    const text = (BigInt(x10000) / 10n).toString();

    return persian ? toPersianDigits(text) : text;
}

/** Basis points as a percentage: 150 -> "1.50%", -42 -> "-0.42%". */
export function bps(value, { persian = false, sign = false } = {}) {
    if (isBlank(value)) {
        return EM_DASH;
    }

    const v = BigInt(value);
    const negative = v < 0n;
    const abs = negative ? -v : v;
    const prefix = negative ? '-' : sign ? '+' : '';
    const text = `${prefix}${fromScaledInt(abs, 2)}٪`;

    return persian ? toPersianDigits(text) : text;
}

/** Plain integer with separators — order counts, trade counts. */
export function count(value, { persian = false } = {}) {
    if (isBlank(value)) {
        return EM_DASH;
    }

    const text = group(BigInt(value).toString());

    return persian ? toPersianDigits(text) : text;
}

/** A signed weight for ledger debit/credit columns: "+250.000" / "-100.000". */
export function signedGrams(milligrams, { persian = false } = {}) {
    if (isBlank(milligrams)) {
        return EM_DASH;
    }

    const value = BigInt(milligrams);
    if (value === 0n) {
        return EM_DASH;
    }

    const text = (value > 0n ? '+' : '') + group(fromScaledInt(value, 3));

    return persian ? toPersianDigits(text) : text;
}

/** A signed rial amount for ledger debit/credit columns. */
export function signedRial(amount, { persian = false } = {}) {
    if (isBlank(amount)) {
        return EM_DASH;
    }

    const value = BigInt(amount);
    if (value === 0n) {
        return EM_DASH;
    }

    const text = (value > 0n ? '+' : '') + group(value.toString());

    return persian ? toPersianDigits(text) : text;
}

export { toPersianDigits };
