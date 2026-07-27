/**
 * Gregorian -> Jalali conversion, integer arithmetic only — doc §1.2,
 * `Utils/jalali.ts`.
 *
 * Line-for-line port of App\Modules\Shared\Support\JalaliDate so a date the API
 * renders as `..._jalali` and a date the panel renders locally can never
 * disagree. The API sends both, but the panel formats locally on list endpoints
 * fetched with `?include_display=false`, which is most of them.
 *
 * Times are converted to the market timezone first. A trade executed at
 * 20:30 UTC happened on the following Tehran day, and a ledger dated by UTC
 * would put it in the wrong statement.
 *
 * @module lib/jalali
 */

/** Cumulative Gregorian day counts at the start of each month, non-leap. */
const GREGORIAN_MONTH_DAYS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

const MARKET_TIMEZONE = 'Asia/Tehran';

const idiv = (a, b) => Math.trunc(a / b);

/**
 * @param {number} gy full Gregorian year
 * @param {number} gm 1..12
 * @param {number} gd 1..31
 * @returns {{year: number, month: number, day: number}}
 */
export function fromGregorian(gy, gm, gd) {
    if (gm < 1 || gm > 12) {
        throw new RangeError(`Month out of range: ${gm}`);
    }

    const shifted = gm > 2 ? gy + 1 : gy;

    let days =
        355666 +
        365 * gy +
        idiv(shifted + 3, 4) -
        idiv(shifted + 99, 100) +
        idiv(shifted + 399, 400) +
        gd +
        GREGORIAN_MONTH_DAYS[gm - 1];

    let jy = -1595 + 33 * idiv(days, 12053);
    days %= 12053;

    jy += 4 * idiv(days, 1461);
    days %= 1461;

    if (days > 365) {
        jy += idiv(days - 1, 365);
        days = (days - 1) % 365;
    }

    let jm;
    let jd;
    if (days < 186) {
        jm = 1 + idiv(days, 31);
        jd = 1 + (days % 31);
    } else {
        jm = 7 + idiv(days - 186, 30);
        jd = 1 + ((days - 186) % 30);
    }

    return { year: jy, month: jm, day: jd };
}

const pad = (n, width) => String(n).padStart(width, '0');

/**
 * Break an instant into its Tehran-local calendar parts without going through
 * a Date's own getters, which report the *browser's* timezone.
 */
function tehranParts(iso, timezone = MARKET_TIMEZONE) {
    const date = iso instanceof Date ? iso : new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

    const parts = {};
    for (const part of formatter.formatToParts(date)) {
        if (part.type !== 'literal') {
            parts[part.type] = part.value;
        }
    }

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
        // Intl renders midnight as "24" in some ICU versions.
        hour: Number(parts.hour) % 24,
        minute: Number(parts.minute),
        second: Number(parts.second),
    };
}

/**
 * "1404/08/05" or "1404/08/05 12:45:33", ASCII digits.
 *
 * Returns an em dash for a null or unparseable input, so a table cell never
 * shows "Invalid Date".
 */
export function formatJalali(iso, { withTime = true, timezone = MARKET_TIMEZONE } = {}) {
    if (iso === null || iso === undefined || iso === '') {
        return '—';
    }

    const local = tehranParts(iso, timezone);
    if (local === null) {
        return '—';
    }

    const j = fromGregorian(local.year, local.month, local.day);
    const date = `${pad(j.year, 4)}/${pad(j.month, 2)}/${pad(j.day, 2)}`;

    return withTime
        ? `${date} ${pad(local.hour, 2)}:${pad(local.minute, 2)}:${pad(local.second, 2)}`
        : date;
}

/** Just the clock, for the terminal header and the trade tape. */
export function formatClock(iso, { seconds = true, timezone = MARKET_TIMEZONE } = {}) {
    const local = tehranParts(iso ?? new Date(), timezone);
    if (local === null) {
        return '—';
    }

    const hm = `${pad(local.hour, 2)}:${pad(local.minute, 2)}`;

    return seconds ? `${hm}:${pad(local.second, 2)}` : hm;
}

/** "۱۲ ثانیه پیش" — the age label next to a stale figure. */
export function formatAge(milliseconds) {
    const seconds = Math.max(0, Math.floor(milliseconds / 1000));

    if (seconds < 60) {
        return `${seconds} ثانیه پیش`;
    }
    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} دقیقه پیش`;
    }
    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)} ساعت پیش`;
    }
    return `${Math.floor(seconds / 86400)} روز پیش`;
}
