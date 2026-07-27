<template>
    <div v-if="offer" class="modal-backdrop" @click.self="emit('close')">
        <div class="modal modal--wide" role="dialog" aria-modal="true" dir="rtl">
            <h2 class="modal__title">پیشنهاد متقابل — {{ offer.offer_code }}</h2>

            <div class="modal__body">
                <dl class="kv">
                    <div>
                        <dt>پیشنهاد فعلی</dt>
                        <dd>
                            <WeightCell :mg="offer.quantity_mg" show-unit />
                            @ <MoneyCell :rial="offer.price_rial" />
                        </dd>
                    </div>
                    <div>
                        <dt>سمت شما</dt>
                        <dd>{{ offer.my_side === 'BUY' ? 'خرید' : 'فروش' }}</dd>
                    </div>
                    <div>
                        <dt>دور</dt>
                        <dd class="num" dir="ltr">{{ offer.round_count }} / {{ offer.max_rounds }}</dd>
                    </div>
                    <div>
                        <dt>مهلت</dt>
                        <dd><Countdown :expires-at="offer.expires_at" /></dd>
                    </div>
                </dl>

                <p v-if="roundsLeft <= 1" class="page__note">
                    این آخرین دور مذاکره است؛ پس از آن پیشنهاد منقضی می‌شود.
                </p>

                <NumberField
                    v-model="quantityMg"
                    label="مقدار خالص (گرم)"
                    :scale="3"
                    :error="fieldErrors.quantity_mg"
                />

                <NumberField
                    v-model="priceRial"
                    label="قیمت پیشنهادی (ریال بر گرم خالص)"
                    :scale="0"
                    :error="fieldErrors.price_rial"
                />

                <label class="field">
                    <span class="field__label">یادداشت (اختیاری)</span>
                    <input v-model="note" class="input" type="text" maxlength="500">
                </label>

                <div class="order-form__summary">
                    <div class="summary-row">
                        <span>تغییر نسبت به پیشنهاد فعلی</span>
                        <span class="num" :class="moveClass" dir="ltr">{{ moveDisplay }}</span>
                    </div>
                    <div class="summary-row summary-row--total">
                        <span>ارزش کل پیشنهاد شما</span>
                        <MoneyCell :rial="notionalRial" show-unit />
                    </div>
                </div>

                <p class="muted">
                    درصد بالا از دید طرف مقابل محاسبه شده است: عدد منفی یعنی پیشنهاد شما برای او
                    بدتر از پیشنهاد فعلی است.
                </p>

                <p v-if="submitError" class="order-form__error">{{ submitError }}</p>
            </div>

            <div class="modal__actions">
                <button
                    class="btn btn--primary"
                    type="button"
                    :disabled="! isValid || submitting"
                    @click="submit"
                >ارسال پیشنهاد متقابل</button>
                <button class="btn btn--ghost" type="button" @click="emit('close')">انصراف</button>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Countering an OTC offer — §4.6, «پیشنهاد متقابل».
 *
 * IDEMPOTENCY. `POST /otc-offers/{id}/counter` carries the `idempotency`
 * middleware, and a counter consumes one of the five rounds §4.6 allows, so a
 * double submit would burn two rounds for one intention. The key is minted when
 * THIS DIALOG OPENS — a watcher on the offer id, so opening the dialog for a
 * different offer mints a different key — and is reused across retries. As
 * everywhere else in the panel: a 409 IDEMPOTENCY_IN_PROGRESS keeps the key, a
 * 422 rotates it.
 *
 * The move percentage is stated FROM THE RECEIVER'S POINT OF VIEW, because that
 * is who has to judge it; see `counterMoveBps` for why a bare signed deviation
 * would tell a buyer and a seller opposite things.
 */
import { computed, ref, watch } from 'vue';

import Countdown from '../Common/Countdown.vue';
import MoneyCell from '../Data/MoneyCell.vue';
import NumberField from '../Common/NumberField.vue';
import WeightCell from '../Data/WeightCell.vue';
import { counterMoveBps, notionalValue, roundsRemaining } from '../../lib/negotiation.js';
import { bps } from '../../lib/format.js';
import { notify, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const props = defineProps({
    /** The OtcOfferResource being countered, or null when the dialog is closed. */
    offer: { type: [Object, null], default: null },
});

const emit = defineEmits(['countered', 'close']);

const api = useApi();

const quantityMg = ref(null);
const priceRial = ref(null);
const note = ref('');
const submitting = ref(false);
const submitError = ref('');
const fieldErrors = ref({});
const idempotencyKey = ref(uuid());

/** A new offer under the cursor is a new negotiation and a new key. */
watch(() => (props.offer ? props.offer.id : null), (id) => {
    submitError.value = '';
    fieldErrors.value = {};
    note.value = '';
    idempotencyKey.value = uuid();

    if (id === null || ! props.offer) {
        quantityMg.value = null;
        priceRial.value = null;
        return;
    }

    // Pre-filled with the standing terms: most counters move the price only,
    // and retyping the quantity is an invitation to a typo.
    quantityMg.value = BigInt(props.offer.quantity_mg);
    priceRial.value = BigInt(props.offer.price_rial);
}, { immediate: true });

const roundsLeft = computed(() => roundsRemaining(props.offer));

const notionalRial = computed(() => {
    if (quantityMg.value === null || priceRial.value === null) {
        return null;
    }
    try {
        return notionalValue(quantityMg.value, priceRial.value).toString();
    } catch {
        return null;
    }
});

/**
 * The receiver is whoever is NOT us, so their side is the mirror of ours —
 * `my_side` is already the viewer's own side per OtcOfferResource.
 */
const receiverSide = computed(() => {
    if (! props.offer) {
        return 'BUY';
    }
    return props.offer.my_side === 'BUY' ? 'SELL' : 'BUY';
});

const moveBps = computed(() => {
    if (! props.offer || priceRial.value === null) {
        return null;
    }
    try {
        return counterMoveBps(props.offer.price_rial, priceRial.value, receiverSide.value);
    } catch {
        return null;
    }
});

const moveDisplay = computed(() => (moveBps.value === null ? '—' : bps(moveBps.value, { sign: true })));

const moveClass = computed(() => {
    if (moveBps.value === null || moveBps.value === 0n) {
        return '';
    }
    return moveBps.value > 0n ? 'is-buy' : 'is-sell';
});

const isValid = computed(() => quantityMg.value !== null && BigInt(quantityMg.value) > 0n
    && priceRial.value !== null && BigInt(priceRial.value) > 0n);

async function submit() {
    if (! isValid.value || submitting.value || ! props.offer) {
        return;
    }

    submitting.value = true;
    submitError.value = '';
    fieldErrors.value = {};

    const body = {
        quantity_mg: Number(quantityMg.value),
        price_rial: Number(priceRial.value),
    };

    if (note.value !== '') {
        body.note = note.value;
    }

    try {
        const { data, replayed } = await api.post(`/otc-offers/${props.offer.id}/counter`, body, {
            idempotencyKey: idempotencyKey.value,
        });

        notify(
            replayed
                ? 'این پیشنهاد متقابل قبلاً ثبت شده بود.'
                : 'پیشنهاد متقابل ارسال شد.',
            'success',
        );

        emit('countered', data);
        emit('close');
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            submitError.value = 'درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.';
        } else {
            submitError.value = error.message || 'ارسال پیشنهاد متقابل ناموفق بود.';
            fieldErrors.value = error.fieldErrors
                ? Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]]))
                : {};
            if (error.status === 422) {
                idempotencyKey.value = uuid();
            }
        }
    } finally {
        submitting.value = false;
    }
}
</script>
