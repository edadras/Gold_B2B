<template>
    <section class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>پیشنهاد توافقی جدید</h2>
            <button class="btn btn--ghost btn--sm" type="button" @click="emit('close')">بستن</button>
        </header>

        <MemberPicker
            ref="picker"
            v-model="form.counterpartyOrganizationId"
            label="گیرنده پیشنهاد"
            :error="fieldErrors.counterparty_organization_id"
        />

        <label class="field">
            <span class="field__label">ابزار</span>
            <select v-model="form.instrument" class="input">
                <option v-for="item in instruments" :key="item.code" :value="item.code">
                    {{ item.code }}
                </option>
            </select>
            <span v-if="fieldErrors.instrument" class="field__error">{{ fieldErrors.instrument }}</span>
        </label>

        <div class="side-toggle">
            <button
                class="side-toggle__btn side-toggle__btn--buy"
                :class="{ 'is-active': form.side === 'BUY' }"
                type="button"
                @click="form.side = 'BUY'"
            >خرید</button>
            <button
                class="side-toggle__btn side-toggle__btn--sell"
                :class="{ 'is-active': form.side === 'SELL' }"
                type="button"
                @click="form.side = 'SELL'"
            >فروش</button>
        </div>

        <NumberField
            v-model="form.quantityMg"
            label="مقدار خالص (گرم)"
            :scale="3"
            placeholder="۵۰۰.۰۰۰"
            hint="مقدار پیشنهاد OTC به وزن خالص بیان می‌شود."
            :error="fieldErrors.quantity_mg"
        />

        <NumberField
            v-model="form.priceRial"
            label="قیمت (ریال بر گرم خالص)"
            :scale="0"
            placeholder="۷۸,۵۰۰,۰۰۰"
            :error="fieldErrors.price_rial"
        />

        <label class="field">
            <span class="field__label">حداقل عیار (در ده‌هزارم)</span>
            <select v-model="form.minPurityX10" class="input">
                <option :value="null">بدون شرط</option>
                <option :value="9950">۹۹۵</option>
                <option :value="9990">۹۹۹</option>
                <option :value="7500">۷۵۰</option>
            </select>
        </label>

        <label class="field">
            <span class="field__label">نوع تحویل</span>
            <select v-model="form.deliveryType" class="input">
                <option :value="null">پیش‌فرض</option>
                <option v-for="option in deliveryTypes" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </label>

        <label class="field">
            <span class="field__label">اعتبار (دقیقه)</span>
            <select v-model.number="form.expiresInMinutes" class="input">
                <option :value="15">۱۵ دقیقه</option>
                <option :value="30">۳۰ دقیقه</option>
                <option :value="60">۱ ساعت</option>
                <option :value="240">۴ ساعت</option>
                <option :value="1440">۲۴ ساعت</option>
            </select>
        </label>

        <div class="order-form__summary">
            <div class="summary-row">
                <span>وزن خالص</span>
                <WeightCell :mg="form.quantityMg === null ? null : form.quantityMg.toString()" show-unit />
            </div>
            <div class="summary-row summary-row--total">
                <span>ارزش کل</span>
                <MoneyCell :rial="notionalRial" show-unit />
            </div>
        </div>

        <p v-if="submitError" class="order-form__error">{{ submitError }}</p>

        <button
            class="btn btn--primary btn--block"
            type="button"
            :disabled="! isValid || submitting"
            @click="confirming = true"
        >
            <span v-if="submitting">در حال ارسال…</span>
            <span v-else>ارسال پیشنهاد</span>
        </button>

        <ConfirmDialog
            :open="confirming"
            title="تأیید پیشنهاد توافقی"
            confirm-label="ارسال پیشنهاد"
            @confirm="submit"
            @cancel="confirming = false"
        >
            <dl class="confirm-list">
                <div><dt>گیرنده</dt><dd class="num" dir="ltr">#{{ form.counterpartyOrganizationId }}</dd></div>
                <div><dt>ابزار</dt><dd dir="ltr">{{ form.instrument }}</dd></div>
                <div><dt>سمت</dt><dd>{{ form.side === 'BUY' ? 'خرید' : 'فروش' }}</dd></div>
                <div><dt>مقدار خالص</dt><dd><WeightCell :mg="form.quantityMg === null ? null : form.quantityMg.toString()" show-unit /></dd></div>
                <div><dt>قیمت</dt><dd><MoneyCell :rial="form.priceRial === null ? null : form.priceRial.toString()" /></dd></div>
                <div><dt>ارزش کل</dt><dd><MoneyCell :rial="notionalRial" show-unit /></dd></div>
            </dl>
            <p class="muted">
                با ارسال این پیشنهاد، موجودی متناظر شما تا زمان پاسخ طرف مقابل رزرو می‌شود.
            </p>
        </ConfirmDialog>
    </section>
</template>

<script setup>
/**
 * Creating an OTC offer — §4.6 step ۱.
 *
 * IDEMPOTENCY (§1.10, and `POST /otc-offers` carries the `idempotency`
 * middleware). The key is minted when the FORM OPENS and reused across every
 * retry of that same offer, exactly as the order ticket does it: an OTC offer
 * reserves the sender's gold, so a double submit that produced two offers would
 * lock twice the metal against one intention. It is rotated only after the
 * server accepts the offer or definitively rejects it (422) — a
 * `409 IDEMPOTENCY_IN_PROGRESS` KEEPS the key, because retrying with that same
 * key is what resolves the in-progress state.
 *
 * ARITHMETIC. `quantity_mg` on an OTC offer is FINE weight — OtcOfferResource
 * renders it with `Display::grams` and OtcOfferController hands it to
 * `FineWeight::fromMilligrams` — so the value shown is F5 over the fine weight
 * with no purity step. The order ticket differs deliberately: there the trader
 * types a gross weight.
 */
import { computed, ref } from 'vue';

import ConfirmDialog from '../Common/ConfirmDialog.vue';
import MemberPicker from '../Common/MemberPicker.vue';
import MoneyCell from '../Data/MoneyCell.vue';
import NumberField from '../Common/NumberField.vue';
import WeightCell from '../Data/WeightCell.vue';
import { enumOptions } from '../../Stores/enums.js';
import { notionalValue } from '../../lib/negotiation.js';
import { notify, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const props = defineProps({
    instruments: { type: Array, default: () => [] },
});

const emit = defineEmits(['created', 'close']);

const api = useApi();

const picker = ref(null);
const confirming = ref(false);
const submitting = ref(false);
const submitError = ref('');
const fieldErrors = ref({});

const blank = () => ({
    counterpartyOrganizationId: null,
    instrument: props.instruments.length ? props.instruments[0].code : '',
    side: 'SELL',
    quantityMg: null,
    priceRial: null,
    minPurityX10: null,
    deliveryType: null,
    expiresInMinutes: 30,
});

const form = ref(blank());

/** §1.10 — minted when the form opens, reused across retries. */
const idempotencyKey = ref(uuid());

const deliveryTypes = computed(() => enumOptions('delivery_type'));

const notionalRial = computed(() => {
    if (form.value.quantityMg === null || form.value.priceRial === null) {
        return null;
    }
    try {
        return notionalValue(form.value.quantityMg, form.value.priceRial).toString();
    } catch {
        return null;
    }
});

const isValid = computed(() => form.value.counterpartyOrganizationId !== null
    && form.value.instrument !== ''
    && form.value.quantityMg !== null && BigInt(form.value.quantityMg) > 0n
    && form.value.priceRial !== null && BigInt(form.value.priceRial) > 0n);

function reset() {
    form.value = blank();
    submitError.value = '';
    fieldErrors.value = {};
    if (picker.value) {
        picker.value.reset();
    }
    // A cleared form is a NEW offer and needs its own key.
    idempotencyKey.value = uuid();
}

async function submit() {
    if (! isValid.value || submitting.value) {
        return;
    }

    confirming.value = false;
    submitting.value = true;
    submitError.value = '';
    fieldErrors.value = {};

    const body = {
        counterparty_organization_id: Number(form.value.counterpartyOrganizationId),
        instrument: form.value.instrument,
        side: form.value.side,
        quantity_mg: Number(form.value.quantityMg),
        price_rial: Number(form.value.priceRial),
        expires_in_minutes: form.value.expiresInMinutes,
    };

    if (form.value.minPurityX10 !== null) {
        body.min_purity_x10 = form.value.minPurityX10;
    }
    if (form.value.deliveryType !== null) {
        body.delivery_type = form.value.deliveryType;
    }

    try {
        const { data, replayed } = await api.post('/otc-offers', body, {
            idempotencyKey: idempotencyKey.value,
        });

        notify(
            replayed
                ? 'این پیشنهاد قبلاً ثبت شده بود؛ همان نتیجه بازگردانده شد.'
                : `پیشنهاد ${data && data.offer_code ? data.offer_code : ''} ارسال شد.`,
            'success',
        );

        emit('created', data);
        reset();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            // Keep the key: retrying with it is what resolves this state.
            submitError.value = 'درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.';
        } else {
            submitError.value = error.message || 'ارسال پیشنهاد ناموفق بود.';
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

defineExpose({ reset, idempotencyKey });
</script>
