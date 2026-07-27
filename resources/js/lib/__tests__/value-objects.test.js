/**
 * The client value objects, checked against the SAME vectors as
 * tests/Unit/Shared/CalculationTest.php, which in turn takes them verbatim from
 * docs/11-appendix/01-formulas.md §1.12.
 *
 * That shared provenance is the point. Two implementations of F1 that agree
 * with each other but not with the doc are still wrong; two that agree with the
 * doc cannot disagree with each other. If the PHP vectors change, these fail.
 *
 * Runs on the Node built-in test runner — no npm package, no build step:
 *
 *     node --test resources/js/lib/__tests__/
 *     composer run test:js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { mulDivCeil, mulDivFloor, mulDivRemainder } from '../int-math.js';
import { fromScaledInt, group, normalizeDigits, toPersianDigits, toScaledInt } from '../numeric-input.js';
import { FineWeight, PricePerFineGram, Purity, Rial, Weight, valuation } from '../value-objects.js';
import { formatJalali, fromGregorian } from '../jalali.js';
import { bps, grams, rial, rialCompact, signedGrams } from '../format.js';

describe('IntMath', () => {
    it('floors toward negative infinity, not toward zero', () => {
        assert.equal(mulDivFloor(-7n, 1n, 2n), -4n);
        assert.equal(mulDivFloor(7n, 1n, 2n), 3n);
    });

    it('ceils away from negative infinity', () => {
        assert.equal(mulDivCeil(-7n, 1n, 2n), -3n);
        assert.equal(mulDivCeil(7n, 1n, 2n), 4n);
    });

    it('keeps the remainder non-negative for a positive divisor', () => {
        assert.equal(mulDivRemainder(-7n, 1n, 2n), 1n);
        assert.equal(mulDivRemainder(7n, 1n, 2n), 1n);
    });

    it('refuses division by zero', () => {
        assert.throws(() => mulDivFloor(1n, 1n, 0n), RangeError);
    });
});

describe('F1 — fine weight from gross (CalculationTest::fineWeightVectors)', () => {
    // [gross_mg, purity_x10000, expected_fine_mg]
    const vectors = [
        [100000n, 10000n, 100000n],
        [100000n, 7500n, 75000n],
        [127420n, 7500n, 95565n],
        [250000n, 9950n, 248750n], // the vector named in the brief
        [1n, 9999n, 0n],
        [3n, 3333n, 0n],
        [0n, 9950n, 0n],
    ];

    for (const [grossMg, purityX10000, expected] of vectors) {
        it(`(${grossMg} mg, ${purityX10000}) -> ${expected} mg`, () => {
            const fine = FineWeight.calculate(
                Weight.fromMilligrams(grossMg),
                Purity.fromScaled(purityX10000),
            );

            assert.equal(fine.milligrams, expected);
        });
    }

    it('rounds DOWN, never up — the system never records gold it does not hold', () => {
        const fine = FineWeight.calculate(Weight.fromMilligrams(1n), Purity.fromScaled(9999n));
        assert.equal(fine.milligrams, 0n);
        assert.equal(FineWeight.roundingRemainder(Weight.fromMilligrams(1n), Purity.fromScaled(9999n)), 9999n);
    });
});

describe('F2 — gross weight required for a fine weight', () => {
    it('ceils: 100.000 g fine from 750 gold needs 133,334 mg gross', () => {
        const gross = FineWeight.fromMilligrams(100000n).requiredGrossAt(Purity.fromPpt(750));
        assert.equal(gross.milligrams, 133334n);
    });

    it('refuses a zero purity', () => {
        assert.throws(
            () => FineWeight.fromMilligrams(1n).requiredGrossAt(Purity.fromScaled(0n)),
            RangeError,
        );
    });
});

describe('F5 — gross amount (CalculationTest::grossAmountVectors)', () => {
    // [fine_mg, price_rial_per_fine_gram, expected_rial]
    const vectors = [
        [248750n, 78480000n, 19521900000n], // the vector named in the brief
        [1000n, 78480000n, 78480000n],
        [1n, 78480000n, 78480n],
    ];

    for (const [fineMg, price, expected] of vectors) {
        it(`(${fineMg} mg, ${price}) -> ${expected} rial`, () => {
            const gross = PricePerFineGram.fromRial(price).valueOf(FineWeight.fromMilligrams(fineMg));
            assert.equal(gross.amount, expected);
        });
    }

    it('stays exact past 2^53, where a float silently would not', () => {
        // 1,234.963841 kg of fine gold at a realistic price. The intermediate
        // product is 9.7e16, past Number.MAX_SAFE_INTEGER (9.0e15), and this
        // particular pair is one where the float result is off by one rial —
        // rounded UP, i.e. in the member's disfavour, and invisible.
        const fineMg = 1234963841n;
        const priceRial = 78481673n;

        assert.ok(fineMg * priceRial > BigInt(Number.MAX_SAFE_INTEGER));

        const gross = PricePerFineGram.fromRial(priceRial).valueOf(FineWeight.fromMilligrams(fineMg));

        assert.equal(gross.amount, 96922028336185n);
        assert.equal(Math.floor((Number(fineMg) * Number(priceRial)) / 1000), 96922028336186);
    });
});

describe('F8 — fee rounds up (CalculationTest::feeVectors)', () => {
    const vectors = [
        [19521900000n, 150n, 29282850n],
        [1n, 150n, 1n], // CEIL: a one-rial trade still owes a rial of fee
        [0n, 150n, 0n],
    ];

    for (const [gross, rate, expected] of vectors) {
        it(`(${gross}, ${rate}) -> ${expected}`, () => {
            assert.equal(Rial.fromRial(gross).rateCeil(rate).amount, expected);
        });
    }
});

describe('worked example 1 — the whole trade, end to end', () => {
    it('reproduces the documented numbers', () => {
        const fine = FineWeight.calculate(Weight.fromMilligrams(250000n), Purity.fromPpt(995));
        const price = PricePerFineGram.fromRial(78480000n);

        const buyer = valuation(fine, price, 150n, 'BUY');
        const seller = valuation(fine, price, 100n, 'SELL');

        assert.equal(fine.milligrams, 248750n);
        assert.equal(buyer.grossAmount.amount, 19521900000n);
        assert.equal(buyer.fee.amount, 29282850n);
        assert.equal(seller.fee.amount, 19521900n);
        assert.equal(buyer.net.amount, 19551182850n);
        assert.equal(seller.net.amount, 19502378100n);
        assert.equal(buyer.fee.plus(seller.fee).amount, 48804750n);
    });

    it('F9 — nets are derived by subtraction so the two sides always balance', () => {
        const fine = FineWeight.fromMilligrams(248750n);
        const price = PricePerFineGram.fromRial(78480000n);

        const buyer = valuation(fine, price, 150n, 'BUY');
        const seller = valuation(fine, price, 100n, 'SELL');

        const platformIncome = buyer.net.minus(seller.net);
        assert.equal(platformIncome.amount, buyer.fee.plus(seller.fee).amount);
    });
});

describe('Purity', () => {
    it('accepts fractional market purity', () => {
        assert.equal(Purity.fromString('995.5').value, 9955n);
        assert.equal(Purity.fromString('995.5').toPpt(), '995.5');
        assert.equal(Purity.fromPpt(995).toPpt(), '995');
    });

    it('refuses values outside 0..10000', () => {
        assert.throws(() => Purity.fromScaled(10001n), RangeError);
        assert.throws(() => Purity.fromScaled(-1n), RangeError);
    });
});

describe('PricePerFineGram', () => {
    it('measures deviation in basis points for the fat-finger guard', () => {
        const reference = PricePerFineGram.fromRial(78480000n);
        assert.equal(PricePerFineGram.fromRial(79264800n).deviationBpsFrom(reference), 100n);
        assert.equal(PricePerFineGram.fromRial(77695200n).deviationBpsFrom(reference), 100n);
    });

    it('validates the tick and steps by whole ticks', () => {
        const price = PricePerFineGram.fromRial(78480000n);
        assert.equal(price.isMultipleOf(10000), true);
        assert.equal(price.isMultipleOf(7n), false);
        assert.equal(price.stepBy(10000n, 1n).rial, 78490000n);
        assert.equal(price.stepBy(10000n, -10n).rial, 78380000n);
    });

    it('never steps below zero', () => {
        assert.equal(PricePerFineGram.fromRial(5000n).stepBy(10000n, -1n).rial, 0n);
    });

    it('applies slippage in the direction that hurts the caller', () => {
        const price = PricePerFineGram.fromRial(78480000n);
        assert.ok(price.worseForBuyer(50).rial > price.rial);
        assert.ok(price.worseForSeller(50).rial < price.rial);
    });
});

describe('Weight', () => {
    it('refuses to go negative', () => {
        assert.throws(() => Weight.fromMilligrams(-1n), RangeError);
        assert.throws(() => Weight.fromMilligrams(1n).minus(Weight.fromMilligrams(2n)), RangeError);
    });

    it('converts to mesghal without float', () => {
        assert.equal(Weight.fromMilligrams(46083n).mesghal(), '10.0000');
        assert.equal(Weight.fromMesghalString('10').milligrams, 46083n);
    });
});

describe('NumericInput', () => {
    it('parses Persian digits and separators', () => {
        assert.equal(toScaledInt('۱,۲۴۷.۳۲۰', 3), 1247320n);
        assert.equal(toScaledInt('۷۸٬۵۱۰٬۰۰۰', 0), 78510000n);
        assert.equal(toScaledInt('۲۵۰٫۵', 3), 250500n);
    });

    it('parses Arabic-Indic digits', () => {
        assert.equal(toScaledInt('١٢٣', 0), 123n);
    });

    it('TRUNCATES excess decimals rather than rounding them up', () => {
        assert.equal(toScaledInt('1.9999', 3), 1999n);
        assert.equal(toScaledInt('0.0009', 3), 0n);
    });

    it('handles the sign and the empty fraction', () => {
        assert.equal(toScaledInt('-250.5', 3), -250500n);
        assert.equal(toScaledInt('+250', 3), 250000n);
        assert.equal(toScaledInt('.5', 1), 5n);
    });

    it('rejects rubbish rather than guessing', () => {
        assert.throws(() => toScaledInt('', 3), RangeError);
        assert.throws(() => toScaledInt('12.3.4', 3), RangeError);
        assert.throws(() => toScaledInt('abc', 3), RangeError);
        assert.throws(() => toScaledInt('.', 3), RangeError);
    });

    it('round-trips through fromScaledInt', () => {
        assert.equal(fromScaledInt(1247320n, 3), '1247.320');
        assert.equal(fromScaledInt(-1247320n, 3), '-1247.320');
        assert.equal(fromScaledInt(5n, 3), '0.005');
        assert.equal(fromScaledInt(78510000n, 0), '78510000');
    });

    it('groups without going through Number, so it survives past 2^53', () => {
        assert.equal(group('19521900000'), '19,521,900,000');
        assert.equal(group('-1247.320'), '-1,247.320');
        assert.equal(group('123456789012345678901'), '123,456,789,012,345,678,901');
    });

    it('normalizes back to ASCII', () => {
        assert.equal(normalizeDigits('۱۲۳٫۴'), '123.4');
        assert.equal(toPersianDigits('1,247.320'), '۱,۲۴۷.۳۲۰');
    });
});

describe('display formatting', () => {
    it('renders weight to exactly three decimals', () => {
        assert.equal(grams(1047320), '1,047.320');
        assert.equal(grams(1047320, { unit: true }), '1,047.320 گرم');
        assert.equal(grams(5), '0.005');
        assert.equal(grams(null), '—');
    });

    it('renders rial with separators and a compact form', () => {
        assert.equal(rial(78510000), '78,510,000');
        assert.equal(rialCompact(4200000000), '4.20 میلیارد');
        assert.equal(rialCompact(-1500000000), '-1.50 میلیارد');
        assert.equal(rialCompact(19521900000), '19.52 میلیارد');
    });

    it('renders basis points as a percentage', () => {
        assert.equal(bps(150), '1.50٪');
        assert.equal(bps(-42), '-0.42٪');
        assert.equal(bps(42, { sign: true }), '+0.42٪');
    });

    it('signs ledger columns and blanks a zero', () => {
        assert.equal(signedGrams(250000), '+250.000');
        assert.equal(signedGrams(-100000), '-100.000');
        assert.equal(signedGrams(0), '—');
    });
});

describe('Jalali conversion', () => {
    it('matches JalaliDate.php on the documented dates', () => {
        assert.deepEqual(fromGregorian(2026, 7, 27), { year: 1405, month: 5, day: 5 });
        assert.deepEqual(fromGregorian(2025, 3, 21), { year: 1404, month: 1, day: 1 });
        assert.deepEqual(fromGregorian(2025, 10, 23), { year: 1404, month: 8, day: 1 });
    });

    it('formats an ISO instant in Tehran time, not the browser timezone', () => {
        // 20:30Z on 2026-07-27 is 00:00 the next day in Tehran (UTC+3:30).
        assert.equal(formatJalali('2026-07-27T20:30:00.000Z', { withTime: false }), '1405/05/06');
        assert.equal(formatJalali('2026-07-27T09:15:33.412Z'), '1405/05/05 12:45:33');
    });

    it('degrades to an em dash rather than "Invalid Date"', () => {
        assert.equal(formatJalali(null), '—');
        assert.equal(formatJalali('not a date'), '—');
    });
});
