/**
 * TypeScript-equivalent value objects — doc §1.2, `Utils/weight.ts`,
 * `Utils/money.ts`.
 *
 * Ports of App\Modules\Shared\ValueObjects. Same names, same factory methods,
 * same rounding directions, same refusals. Where the PHP throws
 * InvalidArgumentException these throw RangeError; nothing else differs.
 *
 * WHY THESE EXIST ON THE CLIENT AT ALL
 * ------------------------------------
 * The panel does arithmetic before it ever talks to the server: the order form
 * shows gross amount, fee and net as the trader types, and the depth ladder
 * shows cumulative quantity. If that arithmetic disagrees with the server's by
 * one rial, the confirmation dialog is lying. So the client runs the same
 * formulas — F1, F2, F5, F8 — over the same integer types.
 *
 * Everything is backed by BigInt. `Number` is exact only to 2^53; a gross
 * amount of 19,551,182,850 rial is fine on its own but
 * `quantity_mg * price_rial` is 1.95e16 and is already past it. A float there
 * does not throw, it just quietly returns a number ending in the wrong digit.
 *
 * @module lib/value-objects
 */

import { mulDivCeil, mulDivFloor, mulDivRemainder } from './int-math.js';
import { fromScaledInt, group, toScaledInt } from './numeric-input.js';

const big = (v) => (typeof v === 'bigint' ? v : BigInt(v));

/** Gross weight, always in milligrams. */
export class Weight {
    static MG_PER_GRAM = 1000n;

    /** 4.6083 g, stored scaled by 10 to stay integral. */
    static MESGHAL_MG_X10 = 46083n;

    /** 31.1034768 g, stored scaled by 10^4. */
    static OUNCE_MG_X10000 = 311034768n;

    constructor(milligrams) {
        const mg = big(milligrams);
        if (mg < 0n) {
            throw new RangeError('Weight cannot be negative');
        }
        this.milligrams = mg;
        Object.freeze(this);
    }

    static fromMilligrams(mg) {
        return new Weight(mg);
    }

    /** Accepts "250", "250.5", "1,247.320" and Persian/Arabic digits. */
    static fromGramsString(input) {
        return new Weight(toScaledInt(input, 3));
    }

    static fromMesghalString(input) {
        const scaled = toScaledInt(input, 4);
        return new Weight(mulDivFloor(scaled, Weight.MESGHAL_MG_X10, 100000n));
    }

    static zero() {
        return new Weight(0n);
    }

    plus(other) {
        return new Weight(this.milligrams + other.milligrams);
    }

    minus(other) {
        return new Weight(this.milligrams - other.milligrams);
    }

    isGreaterThan(other) {
        return this.milligrams > other.milligrams;
    }

    isGreaterThanOrEqual(other) {
        return this.milligrams >= other.milligrams;
    }

    isLessThan(other) {
        return this.milligrams < other.milligrams;
    }

    equals(other) {
        return this.milligrams === other.milligrams;
    }

    isZero() {
        return this.milligrams === 0n;
    }

    /** Exact decimal string in grams, e.g. "1247.320". Display only. */
    grams() {
        return fromScaledInt(this.milligrams, 3);
    }

    /** Grouped for UI, e.g. "1,247.320". Display only. */
    gramsFormatted() {
        return group(this.grams());
    }

    mesghal() {
        return fromScaledInt(mulDivFloor(this.milligrams, 100000n, Weight.MESGHAL_MG_X10), 4);
    }

    toJSON() {
        return Number(this.milligrams);
    }

    toString() {
        return this.grams();
    }
}

/** Gold purity in ten-thousandths: market purity 995 is stored as 9950. */
export class Purity {
    static MIN = 0n;

    static MAX = 10000n;

    static SCALE = 10000n;

    constructor(value) {
        const v = big(value);
        if (v < Purity.MIN || v > Purity.MAX) {
            throw new RangeError(`Purity ${v} is outside 0..10000`);
        }
        this.value = v;
        Object.freeze(this);
    }

    /** Raw storage value (0..10000). */
    static fromScaled(value) {
        return new Purity(value);
    }

    /** From conventional market purity: 750, 995, 999. */
    static fromPpt(ppt) {
        return new Purity(big(ppt) * 10n);
    }

    /** From user input that may carry one decimal: "995.5". */
    static fromString(input) {
        return new Purity(toScaledInt(input, 1));
    }

    static pure() {
        return new Purity(Purity.MAX);
    }

    isAtLeast(other) {
        return this.value >= other.value;
    }

    equals(other) {
        return this.value === other.value;
    }

    /** Conventional display: "995" or "995.5". */
    toPpt() {
        return this.value % 10n === 0n
            ? (this.value / 10n).toString()
            : fromScaledInt(this.value, 1);
    }

    toJSON() {
        return Number(this.value);
    }

    toString() {
        return this.toPpt();
    }
}

/**
 * Pure-gold-equivalent weight in milligrams.
 *
 * A separate type from Weight on purpose: mixing gross and fine is the easiest
 * way to corrupt a gold ledger, and the panel displays both side by side.
 */
export class FineWeight {
    constructor(milligrams) {
        const mg = big(milligrams);
        if (mg < 0n) {
            throw new RangeError('Fine weight cannot be negative');
        }
        this.milligrams = mg;
        Object.freeze(this);
    }

    static fromMilligrams(mg) {
        return new FineWeight(mg);
    }

    static fromGramsString(input) {
        return new FineWeight(toScaledInt(input, 3));
    }

    static zero() {
        return new FineWeight(0n);
    }

    /**
     * F1 — fine = floor(gross * purity / 10000).
     *
     * FLOOR, so the system never records gold it does not hold.
     */
    static calculate(gross, purity) {
        return new FineWeight(mulDivFloor(gross.milligrams, purity.value, Purity.SCALE));
    }

    /** The sub-milligram remainder discarded by calculate(). */
    static roundingRemainder(gross, purity) {
        return mulDivRemainder(gross.milligrams, purity.value, Purity.SCALE);
    }

    /** F2 — gross = ceil(fine * 10000 / purity). CEIL: must be enough. */
    requiredGrossAt(purity) {
        if (purity.value === 0n) {
            throw new RangeError('Cannot derive gross weight at zero purity');
        }
        return new Weight(mulDivCeil(this.milligrams, Purity.SCALE, purity.value));
    }

    plus(other) {
        return new FineWeight(this.milligrams + other.milligrams);
    }

    minus(other) {
        return new FineWeight(this.milligrams - other.milligrams);
    }

    isGreaterThan(other) {
        return this.milligrams > other.milligrams;
    }

    isGreaterThanOrEqual(other) {
        return this.milligrams >= other.milligrams;
    }

    isLessThan(other) {
        return this.milligrams < other.milligrams;
    }

    equals(other) {
        return this.milligrams === other.milligrams;
    }

    isZero() {
        return this.milligrams === 0n;
    }

    min(other) {
        return this.milligrams <= other.milligrams ? this : other;
    }

    grams() {
        return fromScaledInt(this.milligrams, 3);
    }

    gramsFormatted() {
        return group(this.grams());
    }

    toJSON() {
        return Number(this.milligrams);
    }

    toString() {
        return this.grams();
    }
}

/** A rial amount. Signed, because ledger entries carry direction. */
export class Rial {
    /** Rates are hundred-thousandths: 0.15% is 150. */
    static RATE_SCALE = 100000n;

    constructor(amount) {
        this.amount = big(amount);
        Object.freeze(this);
    }

    static fromRial(amount) {
        return new Rial(amount);
    }

    static fromString(input) {
        return new Rial(toScaledInt(input, 0));
    }

    static zero() {
        return new Rial(0n);
    }

    plus(other) {
        return new Rial(this.amount + other.amount);
    }

    minus(other) {
        return new Rial(this.amount - other.amount);
    }

    negate() {
        return new Rial(-this.amount);
    }

    abs() {
        return new Rial(this.amount < 0n ? -this.amount : this.amount);
    }

    /** F8 — apply a rate in hundred-thousandths, rounding UP. */
    rateCeil(rateX100k) {
        return new Rial(mulDivCeil(this.amount, rateX100k, Rial.RATE_SCALE));
    }

    /** Apply a rate rounding DOWN. */
    rateFloor(rateX100k) {
        return new Rial(mulDivFloor(this.amount, rateX100k, Rial.RATE_SCALE));
    }

    isZero() {
        return this.amount === 0n;
    }

    isNegative() {
        return this.amount < 0n;
    }

    isPositive() {
        return this.amount > 0n;
    }

    isGreaterThan(other) {
        return this.amount > other.amount;
    }

    isGreaterThanOrEqual(other) {
        return this.amount >= other.amount;
    }

    isLessThan(other) {
        return this.amount < other.amount;
    }

    equals(other) {
        return this.amount === other.amount;
    }

    formatted() {
        return group(this.amount.toString());
    }

    withUnit() {
        return `${this.formatted()} ریال`;
    }

    toJSON() {
        return Number(this.amount);
    }

    toString() {
        return this.amount.toString();
    }
}

/** Rial per gram of pure gold — the unit the whole market quotes in. */
export class PricePerFineGram {
    static BPS_SCALE = 10000n;

    constructor(rial) {
        const r = big(rial);
        if (r < 0n) {
            throw new RangeError('Price cannot be negative');
        }
        this.rial = r;
        Object.freeze(this);
    }

    static fromRial(rial) {
        return new PricePerFineGram(rial);
    }

    static fromString(input) {
        return new PricePerFineGram(toScaledInt(input, 0));
    }

    static zero() {
        return new PricePerFineGram(0n);
    }

    /** F5 — gross = floor(fine_mg * price / 1000). */
    valueOf(weight) {
        return new Rial(mulDivFloor(weight.milligrams, this.rial, Weight.MG_PER_GRAM));
    }

    /** Slippage upward, rounding up: worst case for a buyer. */
    worseForBuyer(slippageBps) {
        return new PricePerFineGram(
            mulDivCeil(this.rial, PricePerFineGram.BPS_SCALE + big(slippageBps), PricePerFineGram.BPS_SCALE),
        );
    }

    /** Slippage downward, rounding down: worst case for a seller. */
    worseForSeller(slippageBps) {
        return new PricePerFineGram(
            mulDivFloor(this.rial, PricePerFineGram.BPS_SCALE - big(slippageBps), PricePerFineGram.BPS_SCALE),
        );
    }

    /** Deviation from a reference in basis points, always non-negative (F24). */
    deviationBpsFrom(reference) {
        if (reference.rial === 0n) {
            throw new RangeError('Cannot compute deviation from a zero reference');
        }
        const delta = this.rial > reference.rial ? this.rial - reference.rial : reference.rial - this.rial;
        return mulDivFloor(delta, PricePerFineGram.BPS_SCALE, reference.rial);
    }

    isMultipleOf(tickSize) {
        const tick = big(tickSize);
        return tick > 0n && this.rial % tick === 0n;
    }

    /** Step by whole ticks — what the ↑/↓ keys do in the order form. */
    stepBy(tickSize, ticks) {
        const next = this.rial + big(tickSize) * big(ticks);
        return new PricePerFineGram(next < 0n ? 0n : next);
    }

    isGreaterThan(other) {
        return this.rial > other.rial;
    }

    isLessThan(other) {
        return this.rial < other.rial;
    }

    equals(other) {
        return this.rial === other.rial;
    }

    formatted() {
        return group(this.rial.toString());
    }

    toJSON() {
        return Number(this.rial);
    }

    toString() {
        return this.rial.toString();
    }
}

/**
 * F8/F9 — the fee and net amounts shown under the order form before submission.
 *
 * Netting is by SUBTRACTION from the gross, never by recomputing from parts:
 * F9's whole point is that buyer_net - seller_net must equal the platform's
 * income exactly, and two independently rounded figures do not guarantee that.
 *
 * @param {FineWeight} fine
 * @param {PricePerFineGram} price
 * @param {number|bigint} feeRateX100k rate in hundred-thousandths (0.15% = 150)
 * @param {'BUY'|'SELL'} side
 */
export function valuation(fine, price, feeRateX100k, side) {
    const gross = price.valueOf(fine);
    const fee = gross.rateCeil(feeRateX100k);

    return {
        fineWeight: fine,
        grossAmount: gross,
        fee,
        net: side === 'BUY' ? gross.plus(fee) : gross.minus(fee),
    };
}
