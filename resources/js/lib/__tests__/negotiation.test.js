/**
 * OTC and RFQ arithmetic — §4.6 and §4.7.
 *
 * Two things are pinned here that a screen cannot be trusted to get right by
 * inspection: that the notional is computed on BigInt and agrees with the
 * server's F5 to the rial, and that "best quote" inverts with the requester's
 * side. The second is the expensive one — a green "best" marker on the worst
 * price is a bug a member acts on before anybody notices.
 *
 *     node --test resources/js/lib/__tests__/negotiation.test.js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    counterMoveBps,
    coverage,
    expiryBand,
    formatCountdown,
    notionalValue,
    rankQuotes,
    remainingMs,
    roundsRemaining,
} from '../negotiation.js';

describe('notionalValue', () => {
    it('matches the server vector from CalculationTest', () => {
        // (248 750 mg fine, 78 480 000 rial/g) → 19 521 900 000 rial — the same
        // pair pinned in tests/Unit/Shared/CalculationTest.php.
        assert.equal(notionalValue(248_750n, 78_480_000n).amount, 19_521_900_000n);
    });

    it('stays exact past 2^53, where a float silently drifts', () => {
        // A 500 kg block trade: 5e8 mg of fine gold at 78 480 000 rial/g.
        const exact = notionalValue(500_000_000n, 78_480_000n).amount;

        assert.equal(exact, 39_240_000_000_000n);

        // The intermediate product is 3.924e16 — past Number's exact range, so
        // a float implementation has already lost digits before it divides.
        assert.ok(500_000_000 * 78_480_000 > Number.MAX_SAFE_INTEGER);
    });

    it('floors, so the platform never books gold it does not hold', () => {
        // 1 mg × 1 rial/g = 0.001 rial → 0, not 1.
        assert.equal(notionalValue(1n, 1n).amount, 0n);
        // 1999 mg × 1 rial/g = 1.999 → 1.
        assert.equal(notionalValue(1999n, 1n).amount, 1n);
    });

    it('accepts the Numbers the API actually sends', () => {
        assert.equal(notionalValue(250000, 78500000).amount, 19_625_000_000n);
    });
});

describe('counterMoveBps', () => {
    const from = 78_500_000n;

    it('is zero when the price does not move', () => {
        assert.equal(counterMoveBps(from, from, 'BUY'), 0n);
    });

    it('reads a price cut as good news for the receiving buyer', () => {
        // 78,500,000 → 78,450,000 is -6.36 bps of movement.
        const move = counterMoveBps(from, 78_450_000n, 'BUY');

        assert.ok(move > 0n, 'a buyer gains when the price falls');
        assert.equal(move, 6n);
    });

    it('reads the same cut as bad news for the receiving seller', () => {
        const move = counterMoveBps(from, 78_450_000n, 'SELL');

        assert.ok(move < 0n, 'a seller loses when the price falls');
        assert.equal(move, -6n);
    });

    it('mirrors again when the price rises', () => {
        assert.ok(counterMoveBps(from, 78_600_000n, 'SELL') > 0n);
        assert.ok(counterMoveBps(from, 78_600_000n, 'BUY') < 0n);
    });
});

describe('roundsRemaining', () => {
    it('counts down from the offer\'s own maximum', () => {
        assert.equal(roundsRemaining({ round_count: 0, max_rounds: 5 }), 5);
        assert.equal(roundsRemaining({ round_count: 4, max_rounds: 5 }), 1);
    });

    it('never goes negative, and tolerates a missing offer', () => {
        assert.equal(roundsRemaining({ round_count: 9, max_rounds: 5 }), 0);
        assert.equal(roundsRemaining(null), 0);
    });
});

describe('remainingMs and expiryBand', () => {
    const now = Date.parse('2026-03-01T10:00:00Z');

    it('distinguishes "no deadline" from "expired"', () => {
        assert.equal(remainingMs(null, now), null);
        assert.equal(expiryBand(null), 'none');

        assert.equal(remainingMs('2026-03-01T09:59:00Z', now), 0);
        assert.equal(expiryBand(0), 'expired');
    });

    it('floors at zero rather than going negative', () => {
        assert.equal(remainingMs('2026-02-01T00:00:00Z', now), 0);
    });

    it('bands the last minute and the last five minutes separately', () => {
        assert.equal(expiryBand(59_000), 'critical');
        assert.equal(expiryBand(60_000), 'soon');
        assert.equal(expiryBand(299_000), 'soon');
        assert.equal(expiryBand(300_000), 'ok');
    });

    it('returns null for an unparseable timestamp instead of NaN', () => {
        assert.equal(remainingMs('not a date', now), null);
    });
});

describe('formatCountdown', () => {
    it('is mm:ss under an hour and hh:mm:ss above it', () => {
        assert.equal(formatCountdown(0), '00:00');
        assert.equal(formatCountdown(65_000), '01:05');
        assert.equal(formatCountdown(3_600_000), '01:00:00');
        assert.equal(formatCountdown(null), '—');
    });
});

describe('rankQuotes', () => {
    const quote = (id, price, remaining = 1_000_000, status = 'PENDING') => ({
        id,
        price_per_gram_rial: price,
        remaining_mg: remaining,
        status,
    });

    it('puts the LOWEST price first for a buyer', () => {
        const ranked = rankQuotes([
            quote(1, 78_600_000n),
            quote(2, 78_400_000n),
            quote(3, 78_500_000n),
        ], 'BUY');

        assert.deepEqual(ranked.map((entry) => entry.quote.id), [2, 3, 1]);
        assert.equal(ranked[0].isBest, true);
        assert.equal(ranked[1].isBest, false);
    });

    it('puts the HIGHEST price first for a seller — the inversion that matters', () => {
        const ranked = rankQuotes([
            quote(1, 78_600_000n),
            quote(2, 78_400_000n),
            quote(3, 78_500_000n),
        ], 'SELL');

        assert.deepEqual(ranked.map((entry) => entry.quote.id), [1, 3, 2]);
    });

    it('compares on BigInt, so two prices a rial apart past 2^53 do not tie', () => {
        // 2^53 is 9 007 199 254 740 992; the next odd integer is not
        // representable as a double and rounds back down onto it.
        const cheaper = 9_007_199_254_740_992n;
        const dearer = 9_007_199_254_740_993n;

        // Proof the Number comparison this replaces would have tied.
        assert.equal(Number(cheaper) === Number(dearer), true);

        const ranked = rankQuotes([quote(1, dearer), quote(2, cheaper)], 'BUY');

        assert.deepEqual(ranked.map((entry) => entry.quote.id), [2, 1]);
    });

    it('breaks a price tie on the larger remaining quantity, then on id', () => {
        const ranked = rankQuotes([
            quote(7, 78_500_000n, 500_000),
            quote(5, 78_500_000n, 900_000),
            quote(6, 78_500_000n, 900_000),
        ], 'BUY');

        assert.deepEqual(ranked.map((entry) => entry.quote.id), [5, 6, 7]);
    });

    it('never marks a withdrawn, expired or exhausted quote as best', () => {
        const ranked = rankQuotes([
            quote(1, 70_000_000n, 1_000_000, 'WITHDRAWN'),
            quote(2, 78_000_000n, 0, 'PENDING'),
            quote(3, 78_500_000n),
        ], 'BUY');

        assert.equal(ranked[0].quote.id, 3);
        assert.equal(ranked[0].isBest, true);
        // The dead ones are still listed — hiding them would lose the history.
        assert.equal(ranked.length, 3);
        assert.deepEqual(ranked.slice(1).map((entry) => entry.rank), [null, null]);
    });

    it('tolerates a missing list', () => {
        assert.deepEqual(rankQuotes(null, 'BUY'), []);
    });
});

describe('coverage', () => {
    it('caps the fill at whichever side is smaller', () => {
        const result = coverage({ remaining_mg: 5_000_000, allow_partial: true }, { remaining_mg: 2_000_000 });

        assert.equal(result.fillableMg, 2_000_000n);
        assert.equal(result.isPartial, true);
        assert.equal(result.isUsable, true);
    });

    it('refuses a partial fill when the requester did not allow one', () => {
        const result = coverage({ remaining_mg: 5_000_000, allow_partial: false }, { remaining_mg: 2_000_000 });

        assert.equal(result.isPartial, true);
        assert.equal(result.isUsable, false);
    });

    it('is a full fill when the quote covers the request exactly', () => {
        const result = coverage({ remaining_mg: 5_000_000, allow_partial: false }, { remaining_mg: 5_000_000 });

        assert.equal(result.isPartial, false);
        assert.equal(result.isUsable, true);
    });

    it('is unusable, not a crash, when either side is absent', () => {
        assert.equal(coverage(null, null).isUsable, false);
        assert.equal(coverage({ remaining_mg: 1 }, null).fillableMg, 0n);
    });
});
