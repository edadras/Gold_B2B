<template>
    <section class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>درخواست قیمت جدید</h2>
            <button class="btn btn--ghost btn--sm" type="button" @click="emit('close')">بستن</button>
        </header>

        <label class="field">
            <span class="field__label">ابزار</span>
            <select v-model="form.instrument" class="input">
                <option v-for="item in instruments" :key="item.code" :value="item.code">{{ item.code }}</option>
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
            placeholder="۵,۰۰۰.۰۰۰"
            :error="fieldErrors.quantity_mg"
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
            <span class="field__label">دامنه ارسال</span>
            <select v-model="form.visibility" class="input">
                <option value="ALL_QUALIFIED">همه اعضای واجد شرایط</option>
                <option value="SELECTED">اعضای منتخب</option>
                <option value="ANONYMOUS">ناشناس</option>
            </select>
            <span class="field__hint">{{ visibilityHint }}</span>
        </label>

        <MemberPicker
            v-if="form.visibility === 'SELECTED'"
            ref="picker"
            v-model="form.recipientOrganizationIds"
            label="اعضای دریافت‌کننده"
            multiple
            :error="fieldErrors.recipient_organization_ids"
        />

        <label class="field field--inline">
            <input v-model="form.allowPartial" type="checkbox">
            <span class="field__label">پذیرش پیشنهاد جزئی</span>
        </label>

        <label class="field">
            <span class="field__label">اعتبار (دقیقه)</span>
            <select v-model.number="form.expiresInMinutes" class="input">
                <option :value="15">۱۵ دقیقه</option>
                <option :value="30">۳۰ دقیقه</option>
                <option :value="60">۱ ساعت</option>
                <option :value="240">۴ ساعت</option>
            </select>
        </label>

        <p v-if="submitError" class="order-form__error">{{ submitError }}</p>

        <button
            class="btn btn--primary btn--block"
            type="button"
            :disabled="! isValid || submitting"
            @click="submit"
        >
            <span v-if="submitting">در حال ارسال…</span>
            <span v-else>ارسال درخواست</span>
        </button>
    </section>
</template>

<script setup>
/**
 * Creating an RFQ — §4.7.
 *
 * IDEMPOTENCY. `POST /rfqs` carries the `idempotency` middleware. The key is
 * minted when the form opens and reused for every retry of that request, so a
 * member who resubmits after a timeout does not send the same enquiry to the
 * whole market twice — an RFQ is broadcast, and a duplicate is visible to every
 * recipient. Rotated after acceptance or a 422; a 409 IDEMPOTENCY_IN_PROGRESS
 * keeps it.
 *
 * VISIBILITY is spelled out in the field hint rather than left to the enum
 * name, because ANONYMOUS has a consequence (§4.7 rule ۵: the requester's
 * identity is hidden until acceptance) that a member choosing it needs stated.
 */
import { computed, ref } from 'vue';

import MemberPicker from '../Common/MemberPicker.vue';
import NumberField from '../Common/NumberField.vue';
import { notify, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const props = defineProps({
    instruments: { type: Array, default: () => [] },
});

const emit = defineEmits(['created', 'close']);

const api = useApi();

const picker = ref(null);
const submitting = ref(false);
const submitError = ref('');
const fieldErrors = ref({});

const blank = () => ({
    instrument: props.instruments.length ? props.instruments[0].code : '',
    side: 'BUY',
    quantityMg: null,
    minPurityX10: null,
    visibility: 'ALL_QUALIFIED',
    recipientOrganizationIds: [],
    allowPartial: true,
    expiresInMinutes: 15,
});

const form = ref(blank());

/** §1.10 — minted when the form opens, reused across retries. */
const idempotencyKey = ref(uuid());

const visibilityHint = computed(() => ({
    ALL_QUALIFIED: 'همه اعضای واجد شرایط درخواست را می‌بینند و هویت شما مشخص است.',
    SELECTED: 'فقط اعضای انتخاب‌شده درخواست را می‌بینند.',
    ANONYMOUS: 'هویت شما تا لحظه پذیرش پیشنهاد برای پیشنهاددهندگان مخفی می‌ماند.',
}[form.value.visibility] || ''));

const isValid = computed(() => form.value.instrument !== ''
    && form.value.quantityMg !== null && BigInt(form.value.quantityMg) > 0n
    && (form.value.visibility !== 'SELECTED' || form.value.recipientOrganizationIds.length > 0));

function reset() {
    form.value = blank();
    submitError.value = '';
    fieldErrors.value = {};
    if (picker.value) {
        picker.value.reset();
    }
    idempotencyKey.value = uuid();
}

async function submit() {
    if (! isValid.value || submitting.value) {
        return;
    }

    submitting.value = true;
    submitError.value = '';
    fieldErrors.value = {};

    const body = {
        instrument: form.value.instrument,
        side: form.value.side,
        quantity_mg: Number(form.value.quantityMg),
        visibility: form.value.visibility,
        allow_partial: form.value.allowPartial,
        expires_in_minutes: form.value.expiresInMinutes,
    };

    if (form.value.minPurityX10 !== null) {
        body.min_purity_x10 = form.value.minPurityX10;
    }
    if (form.value.visibility === 'SELECTED') {
        body.recipient_organization_ids = form.value.recipientOrganizationIds;
    }

    try {
        const { data, replayed } = await api.post('/rfqs', body, {
            idempotencyKey: idempotencyKey.value,
        });

        notify(
            replayed
                ? 'این درخواست قبلاً ثبت شده بود؛ همان نتیجه بازگردانده شد.'
                : `درخواست ${data && data.rfq_code ? data.rfq_code : ''} ارسال شد.`,
            'success',
        );

        emit('created', data);
        reset();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            submitError.value = 'درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.';
        } else {
            submitError.value = error.message || 'ارسال درخواست ناموفق بود.';
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
