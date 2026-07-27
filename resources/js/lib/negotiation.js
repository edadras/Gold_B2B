/**
 * Pure arithmetic for the two bilateral screens — OTC (§4.6) and RFQ (§4.7).
 *
 * Everything the negotiation screens compute locally lives here rather than in
 * a component, for the same reason `valuation()` lives in value-objects.js: the
 * figures a member reads before pressing «پذیرش» must be the figures the server
 * will book, and a formula buried in a template cannot be tested against the
 * server's vectors.
 *
 * NO FLOATS. Quantities are milligrams, prices are rial per fine gram, and both
 * are BigInt end to end. `quantity_mg * price_rial` for a five-kilogram RFQ is
 * about 3.9e17 — already past `Number.MAX_SAFE_INTEGER`, so a Number here would
 * not throw, it would silently quote the wrong price.
 *
 * @module lib/negotiation
 */

import { FineWeight, PricePerFineGram } from './value-objects.js';

const big = (v) => (typeof v === 'bigint' ? v : BigInt(v));

/**
 * What an OTC offer or an RFQ quote is worth, by F5.
 *
 * Both endpoints quote in FINE weight — `quantity_mg` on an OtcOffer and on an
 * RfqQuote is already pure-gold-equivalent (see OtcOfferResource /
 * RfqQuoteResource) — so there is no purity step here. That is the difference
 * from the order ticket, where the trader types a GROSS weight.
 *
 * @param {bigint|number|string} quantityMg fine weight in milligrams
 * @param {bigint|number|string} priceRial rial per fine gram
 * @returns {Rial}
 */
export function notionalValue(quantityMg, priceRial) {
    return PricePerFineGram.fromRial(big(priceRial))
        .valueOf(FineWeight.fromMilligrams(big(quantityMg)));
}

/**
 * The move a counter-offer makes, in basis points, signed from the point of
 * view of the party who RECEIVES it.
 *
 * A negative number is always the worse outcome for the receiver: a counter
 * that lowers the price is bad news for a seller and good news for a buyer, and
 * a screen that printed a bare "-0.04%" for both would be telling one of them
 * the opposite of the truth.
 *
 * @param {bigint|number|string} fromRial the price being countered
 * @param {bigint|number|string} toRial the price proposed instead
 * @param {'BUY'|'SELL'} receiverSide the side the RECEIVER is on
 * @returns {bigint} basis points, signed
 */
export function counterMoveBps(fromRial, toRial, receiverSide) {
    const from = PricePerFineGram.fromRial(big(fromRial));
    const to = PricePerFineGram.fromRial(big(toRial));

    // Magnitude comes from the value object so the client and the server round
    // a deviation the same way (F24, floor).
    const magnitude = to.deviationBpsFrom(from);

    if (magnitude === 0n) {
        return 0n;
    }

    const cheaper = to.isLessThan(from);
    // A receiver who is BUYING gains when the price falls; one who is SELLING
    // gains when it rises.
    const favourable = receiverSide === 'BUY' ? cheaper : ! cheaper;

    return favourable ? magnitude : -magnitude;
}

/** How many counter rounds are left before an OTC offer expires by rule (§4.6). */
export function roundsRemaining(offer) {
    if (! offer) {
        return 0;
    }

    const used = Number(offer.round_count ?? 0);
    const max = Number(offer.max_rounds ?? 0);

    return Math.max(0, max - used);
}

/**
 * Milliseconds until an ISO deadline, floored at zero.
 *
 * `null` — not `Infinity` and not `0` — when there is no deadline, so a caller
 * can tell "never expires" apart from "expired", which are opposite facts.
 */
export function remainingMs(expiresAtIso, nowMs = Date.now()) {
    if (expiresAtIso === null || expiresAtIso === undefined || expiresAtIso === '') {
        return null;
    }

    const deadline = Date.parse(expiresAtIso);

    if (Number.isNaN(deadline)) {
        return null;
    }

    return Math.max(0, deadline - nowMs);
}

/**
 * The urgency band a countdown falls in.
 *
 * An OTC offer's default life is thirty minutes and an RFQ quote's is often
 * fifteen, so "under a minute" and "under five minutes" are the two thresholds
 * that change what a member does. `expired` is its own band because an expired
 * offer must stop offering an accept button rather than merely turning red.
 *
 * @returns {'none'|'expired'|'critical'|'soon'|'ok'}
 */
export function expiryBand(remaining) {
    if (remaining === null) {
        return 'none';
    }
    if (remaining <= 0) {
        return 'expired';
    }
    if (remaining < 60_000) {
        return 'critical';
    }
    if (remaining < 300_000) {
        return 'soon';
    }
    return 'ok';
}

/**
 * A countdown as Persian-free ASCII digits, for placement inside `.num`.
 *
 * Deliberately not `formatAge()`: that one reads "۴۲ ثانیه پیش" and describes
 * the past. A deadline needs mm:ss, because "۲ دقیقه" is not precise enough to
 * decide whether there is time to counter.
 */
export function formatCountdown(remaining) {
    if (remaining === null) {
        return '—';
    }
    if (remaining <= 0) {
        return '00:00';
    }

    const totalSeconds = Math.floor(remaining / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (n) => String(n).padStart(2, '0');

    return hours > 0
        ? `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
        : `${pad(minutes)}:${pad(seconds)}`;
}

/**
 * RFQ quotes in the order the requester should read them — best first.
 *
 * BEST DEPENDS ON THE REQUESTER'S SIDE, and getting it backwards is the single
 * most expensive bug this screen could have: a requester BUYING wants the
 * LOWEST price, one SELLING wants the HIGHEST. Ties break on the larger
 * remaining quantity, then on the lower id, so the order is total and stable
 * across refreshes rather than shuffling under the cursor.
 *
 * Only live quotes are ranked — a withdrawn or expired quote is not an option,
 * and marking one "best" would invite a member to press an accept button the
 * server will refuse. They are returned after the live ones with `rank: null`.
 *
 * Comparison is on BigInt prices. Sorting on `Number(price)` would compare
 * equal for two prices differing in the last rial once past 2^53.
 *
 * @param {Array<object>} quotes RfqQuoteResource payloads
 * @param {'BUY'|'SELL'} requesterSide the RFQ's own side
 * @returns {Array<object>} the same objects, wrapped as {quote, rank, isBest}
 */
export function rankQuotes(quotes, requesterSide) {
    const list = Array.isArray(quotes) ? quotes : [];

    const isLive = (quote) => quote && quote.status === 'PENDING' && BigInt(quote.remaining_mg ?? 0) > 0n;

    const live = list.filter(isLive);
    const dead = list.filter((quote) => ! isLive(quote));

    const better = (a, b) => {
        const priceA = BigInt(a.price_per_gram_rial ?? 0);
        const priceB = BigInt(b.price_per_gram_rial ?? 0);

        if (priceA !== priceB) {
            const aFirst = requesterSide === 'BUY' ? priceA < priceB : priceA > priceB;
            return aFirst ? -1 : 1;
        }

        const remainingA = BigInt(a.remaining_mg ?? 0);
        const remainingB = BigInt(b.remaining_mg ?? 0);

        if (remainingA !== remainingB) {
            return remainingA > remainingB ? -1 : 1;
        }

        return Number(a.id ?? 0) - Number(b.id ?? 0);
    };

    const sorted = [...live].sort(better);

    return [
        ...sorted.map((quote, index) => ({ quote, rank: index + 1, isBest: index === 0 })),
        ...dead.map((quote) => ({ quote, rank: null, isBest: false })),
    ];
}

/**
 * How much of an RFQ is still open, and whether a quote can cover it.
 *
 * Returned as BigInt milligrams so the caller can hand it straight to
 * `FineWeight` without a lossy hop through Number.
 */
export function coverage(rfq, quote) {
    const wanted = big(rfq && rfq.remaining_mg !== undefined && rfq.remaining_mg !== null
        ? rfq.remaining_mg
        : 0);
    const offered = big(quote && quote.remaining_mg !== undefined && quote.remaining_mg !== null
        ? quote.remaining_mg
        : 0);

    const fillable = offered < wanted ? offered : wanted;

    return {
        wantedMg: wanted,
        offeredMg: offered,
        fillableMg: fillable,
        // A quote smaller than the request is only usable when the requester
        // said partial fills are acceptable; the server enforces this too.
        isPartial: fillable < wanted,
        isUsable: fillable > 0n && (fillable === wanted || Boolean(rfq && rfq.allow_partial)),
    };
}
