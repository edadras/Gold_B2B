<template>
    <section class="order-form" dir="rtl">
        <header class="order-form__header">
            <h2>ثبت سفارش</h2>
        </header>

        <div class="side-toggle">
            <button
                class="side-toggle__btn side-toggle__btn--buy"
                :class="{ 'is-active': form.side === 'BUY' }"
                type="button"
                @click="setSide('BUY')"
            >
                خرید <kbd dir="ltr">B</kbd>
            </button>
            <button
                class="side-toggle__btn side-toggle__btn--sell"
                :class="{ 'is-active': form.side === 'SELL' }"
                type="button"
                @click="setSide('SELL')"
            >
                فروش <kbd dir="ltr">S</kbd>
            </button>
        </div>

        <label class="field">
            <span class="field__label">نوع</span>
            <select v-model="form.type" class="input">
                <option value="LIMIT">محدود</option>
                <option value="MARKET">بازار</option>
            </select>
        </label>

        <NumberField
            ref="quantityField"
            v-model="form.quantityMg"
            label="مقدار (گرم)"
            :scale="3"
            placeholder="۳۰۰.۰۰۰"
            :hint="quantityHint"
            :error="fieldErrors.quantity_mg"
        />

        <NumberField
            v-if="form.type === 'LIMIT'"
            ref="priceField"
            v-model="form.priceRial"
            label="قیمت (ریال بر گرم خالص)"
            :scale="0"
            placeholder="۷۸,۵۰۰,۰۰۰"
            :hint="priceHint"
            :error="fieldErrors.price_rial"
        />

        <label class="field">
            <span class="field__label">اعتبار</span>
            <select v-model="form.timeInForce" class="input">
                <option value="DAY">تا پایان روز</option>
                <option value="GTC">تا لغو</option>
                <option value="IOC">فوری یا لغو</option>
                <option value="FOK">کامل یا هیچ</option>
            </select>
        </label>

        <div class="order-form__summary">
            <div class="summary-row">
                <span>وزن خالص</span>
                <span class="num" dir="ltr">{{ fineDisplay }}</span>
            </div>
            <div class="summary-row">
                <span>ناخالص</span>
                <span class="num" dir="ltr">{{ grossDisplay }}</span>
            </div>
            <div class="summary-row">
                <span>کارمزد</span>
                <span class="num" dir="ltr">{{ feeDisplay }}</span>
            </div>
            <div class="summary-row summary-row--total">
                <span>خالص</span>
                <span class="num" dir="ltr">{{ netDisplay }}</span>
            </div>
        </div>

        <p v-if="submitError" class="order-form__error">{{ submitError }}</p>

        <button
            class="btn btn--primary btn--block"
            :class="form.side === 'BUY' ? 'btn--buy' : 'btn--sell'"
            type="button"
            :disabled="!isValid || submitting"
            @click="requestSubmit"
        >
            <span v-if="submitting">در حال ارسال…</span>
            <span v-else>{{ form.side === 'BUY' ? 'ثبت سفارش خرید' : 'ثبت سفارش فروش' }}</span>
        </button>

        <p class="order-form__hint">
            Enter ثبت با تأیید · Ctrl+Enter ثبت بدون تأیید · Esc پاک کردن
        </p>

        <ConfirmDialog
            :open="confirming"
            :title="form.side === 'BUY' ? 'تأیید سفارش خرید' : 'تأیید سفارش فروش'"
            confirm-label="ثبت سفارش"
            @confirm="submit"
            @cancel="confirming = false"
        >
            <dl class="confirm-list">
                <div><dt>ابزار</dt><dd dir="ltr">{{ instrument ? instrument.code : '—' }}</dd></div>
                <div><dt>سمت</dt><dd>{{ form.side === 'BUY' ? 'خرید' : 'فروش' }}</dd></div>
                <div><dt>مقدار</dt><dd class="num" dir="ltr">{{ quantityDisplay }}</dd></div>
                <div><dt>قیمت</dt><dd class="num" dir="ltr">{{ priceDisplay }}</dd></div>
                <div><dt>خالص</dt><dd class="num" dir="ltr">{{ netDisplay }}</dd></div>
            </dl>
        </ConfirmDialog>
    </section>
</template>

<script setup>
/**
 * The order ticket — doc §1.3.
 *
 * IDEMPOTENCY (§1.10). The key is generated when the FORM IS OPENED, not when
 * submit is pressed, and it is reused for every retry of that same ticket. That
 * is the whole point of the header: a trader who hits submit, sees a timeout,
 * and hits submit again must place ONE order, not two. The key is rotated only
 * after a submission that the server accepted or definitively rejected — at
 * which point the ticket is a new one.
 *
 * ARITHMETIC. Gross, fee and net are computed locally with the same integer
 * formulas the server uses (F1/F5/F8, via lib/value-objects.js), so the
 * confirmation dialog shows the number the member will actually be charged. If
 * the two ever disagree the bug is visible immediately rather than at
 * settlement.
 */
import { computed, ref, watch } from 'vue';

import ConfirmDialog from '../Common/ConfirmDialog.vue';
import NumberField from '../Common/NumberField.vue';
import { FineWeight, PricePerFineGram, Purity, Rial, Weight, valuation } from '../../lib/value-objects.js';
import { grams, rial } from '../../lib/format.js';
import { uuid } from '../../lib/api.js';
import { notify, useApi } from '../../Stores/panel.js';

const props = defineProps({
    instrument: { type: Object, default: null },
    /** Fee rate in hundred-thousandths, from /meta/settings. */
    feeRateX100k: { type: [Number, String], default: 150 },
    /**
     * Where ↑/↓ start from when the price field is empty. Pressing ↑ on a blank
     * ticket should step off the market, not off zero.
     */
    referencePriceRial: { type: [Number, String, null], default: null },
});

const emit = defineEmits(['placed']);

const api = useApi();

const form = ref({
    side: 'BUY',
    type: 'LIMIT',
    quantityMg: null,
    priceRial: null,
    timeInForce: 'DAY',
});

const confirming = ref(false);
const submitting = ref(false);
const submitError = ref('');
const fieldErrors = ref({});
const quantityField = ref(null);
const priceField = ref(null);

/** §1.10 — minted when the ticket opens, reused across retries. */
const idempotencyKey = ref(uuid());

function newTicket() {
    idempotencyKey.value = uuid();
}

const tickSize = computed(() => BigInt((props.instrument && props.instrument.tick_size_rial) || 10000));

/**
 * The quantity a member types is GROSS weight at the instrument's minimum
 * purity; the ledger and the price are in FINE weight. Showing both prevents
 * the classic mistake of reading a fine figure as a gross one.
 */
const fine = computed(() => {
    if (form.value.quantityMg === null) {
        return null;
    }
    try {
        const purity = Purity.fromScaled((props.instrument && props.instrument.min_purity_x10) || 10000);
        return FineWeight.calculate(Weight.fromMilligrams(form.value.quantityMg), purity);
    } catch {
        return null;
    }
});

const computedValuation = computed(() => {
    if (fine.value === null || form.value.priceRial === null || form.value.type !== 'LIMIT') {
        return null;
    }
    try {
        return valuation(
            fine.value,
            PricePerFineGram.fromRial(form.value.priceRial),
            BigInt(props.feeRateX100k),
            form.value.side,
        );
    } catch {
        return null;
    }
});

const fineDisplay = computed(() => (fine.value === null ? '—' : `${fine.value.gramsFormatted()} گرم`));
const grossDisplay = computed(() => (computedValuation.value === null ? '—' : computedValuation.value.grossAmount.formatted()));
const feeDisplay = computed(() => (computedValuation.value === null ? '—' : computedValuation.value.fee.formatted()));
const netDisplay = computed(() => (computedValuation.value === null ? '—' : computedValuation.value.net.formatted()));
const quantityDisplay = computed(() => grams(form.value.quantityMg, { unit: true }));
const priceDisplay = computed(() => (form.value.type === 'MARKET' ? 'بازار' : rial(form.value.priceRial)));

const quantityHint = computed(() => {
    if (!props.instrument) {
        return '';
    }
    return `حداقل ${grams(props.instrument.min_order_mg)} — حداکثر ${grams(props.instrument.max_order_mg)} گرم`;
});

const priceHint = computed(() => {
    if (!props.instrument) {
        return '';
    }
    return `گام قیمتی ${rial(props.instrument.tick_size_rial)} ریال`;
});

const isValid = computed(() => {
    if (form.value.quantityMg === null || BigInt(form.value.quantityMg) <= 0n) {
        return false;
    }
    if (form.value.type === 'LIMIT') {
        if (form.value.priceRial === null || BigInt(form.value.priceRial) <= 0n) {
            return false;
        }
        // Refuse an off-tick price here rather than making the round trip:
        // the server returns ORDER_PRICE_INVALID_TICK for it anyway.
        if (BigInt(form.value.priceRial) % tickSize.value !== 0n) {
            return false;
        }
    }
    return true;
});

function setSide(side) {
    form.value.side = side;
}

/** B / S — focus the ticket on the requested side. */
function focusSide(side) {
    setSide(side);
    if (quantityField.value) {
        quantityField.value.focus();
    }
}

/** Esc — clear the ticket and start a new idempotency key with it. */
function clear() {
    form.value = { side: form.value.side, type: 'LIMIT', quantityMg: null, priceRial: null, timeInForce: 'DAY' };
    submitError.value = '';
    fieldErrors.value = {};
    confirming.value = false;
    newTicket();
}

/** ↑ / ↓ (and Shift for ten) — step the limit price by whole ticks. */
function stepPrice(ticks) {
    if (form.value.type !== 'LIMIT') {
        return;
    }
    const base = form.value.priceRial === null
        ? PricePerFineGram.fromRial(referencePrice())
        : PricePerFineGram.fromRial(form.value.priceRial);

    form.value.priceRial = base.stepBy(tickSize.value, BigInt(ticks)).rial;
}

/**
 * The market price rounded DOWN to a whole tick. Stepping from an off-tick
 * reference would leave every subsequent step off-tick too, and the server
 * would reject the order with ORDER_PRICE_INVALID_TICK.
 */
function referencePrice() {
    if (props.referencePriceRial === null) {
        return 0n;
    }
    const reference = BigInt(props.referencePriceRial);
    return reference - (reference % tickSize.value);
}

/** Depth-ladder click: side, price and optionally the whole level's size. */
function applyPick({ side, price, quantityMg }) {
    form.value.side = side;
    form.value.type = 'LIMIT';
    form.value.priceRial = BigInt(price);
    if (form.value.quantityMg === null && quantityMg) {
        form.value.quantityMg = BigInt(quantityMg);
    }
}

function requestSubmit() {
    if (!isValid.value) {
        return;
    }
    confirming.value = true;
}

async function submit() {
    if (!isValid.value || submitting.value) {
        return;
    }

    confirming.value = false;
    submitting.value = true;
    submitError.value = '';
    fieldErrors.value = {};

    const body = {
        instrument: props.instrument ? props.instrument.code : null,
        side: form.value.side,
        type: form.value.type,
        time_in_force: form.value.timeInForce,
        quantity_mg: Number(form.value.quantityMg),
    };

    if (form.value.type === 'LIMIT') {
        body.price_rial = Number(form.value.priceRial);
    }

    try {
        const { data, replayed } = await api.post('/orders', body, {
            idempotencyKey: idempotencyKey.value,
        });

        notify(
            replayed
                ? 'این سفارش قبلاً ثبت شده بود؛ همان نتیجه بازگردانده شد.'
                : `سفارش ${data && data.order_code ? data.order_code : ''} ثبت شد.`,
            'success',
        );

        emit('placed', data);
        clear();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            // Same ticket already being processed. Keep the key: retrying with
            // it is exactly what resolves this, and a new key would double up.
            submitError.value = 'درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.';
        } else {
            submitError.value = error.message || 'ثبت سفارش ناموفق بود.';
            fieldErrors.value = error.fieldErrors
                ? Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]]))
                : {};
            // A definitive business rejection ends this ticket: the next
            // attempt is a different order and needs its own key.
            if (error.status === 422 && error.code !== 'IDEMPOTENCY_IN_PROGRESS') {
                newTicket();
            }
        }
    } finally {
        submitting.value = false;
    }
}

/** Switching instrument invalidates the ticket, price included. */
watch(() => (props.instrument ? props.instrument.code : null), () => clear());

defineExpose({ focusSide, clear, stepPrice, applyPick, submit, requestSubmit, form, idempotencyKey });
</script>
