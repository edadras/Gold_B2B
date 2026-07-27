<template>
    <section class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>ثبت اختلاف جدید</h2>
            <button class="btn btn--ghost btn--sm" type="button" @click="emit('close')">بستن</button>
        </header>

        <label class="field">
            <span class="field__label">نوع اختلاف</span>
            <select v-model="form.disputeType" class="input">
                <option v-for="option in disputeTypes" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <span v-if="fieldErrors.dispute_type" class="field__error">{{ fieldErrors.dispute_type }}</span>
        </label>

        <label class="field">
            <span class="field__label">شناسه معامله</span>
            <input v-model.number="form.tradeId" class="input num" dir="ltr" type="number" min="1">
            <span class="field__hint">
                با ذکر معامله، طرف مقابل و مبلغ مورد اختلاف از خود معامله استخراج می‌شود.
            </span>
            <span v-if="fieldErrors.trade_id" class="field__error">{{ fieldErrors.trade_id }}</span>
        </label>

        <label v-if="! form.tradeId" class="field">
            <span class="field__label">شناسه عضو طرف اختلاف</span>
            <input v-model.number="form.respondentOrgId" class="input num" dir="ltr" type="number" min="1">
            <span class="field__hint">بدون معامله، ذکر طرف مقابل الزامی است.</span>
            <span v-if="fieldErrors.respondent_org_id" class="field__error">{{ fieldErrors.respondent_org_id }}</span>
        </label>

        <label class="field">
            <span class="field__label">شناسه تسویه (اختیاری)</span>
            <input v-model.number="form.settlementId" class="input num" dir="ltr" type="number" min="1">
        </label>

        <label v-if="form.disputeType === 'PURITY_MISMATCH'" class="field">
            <span class="field__label">عیار واقعی (در ده‌هزارم)</span>
            <input v-model.number="form.actualPurityX10k" class="input num" dir="ltr" type="number" min="1" max="10000">
            <span class="field__hint">عیار ۹۹۵ یعنی ۹۹۵۰.</span>
            <span v-if="fieldErrors.actual_purity_x10k" class="field__error">{{ fieldErrors.actual_purity_x10k }}</span>
        </label>

        <NumberField
            v-if="form.disputeType === 'WEIGHT_MISMATCH'"
            v-model="form.actualFineMg"
            label="وزن خالص واقعی (گرم)"
            :scale="3"
            :error="fieldErrors.actual_fine_mg"
        />

        <NumberField
            v-if="claimAmountApplies"
            v-model="form.claimRial"
            label="مبلغ مورد ادعا (ریال)"
            :scale="0"
            hint="برای اختلاف وزن و عیار، مبلغ توسط سامانه از معامله محاسبه می‌شود."
            :error="fieldErrors.claim_rial"
        />

        <label class="field">
            <span class="field__label">شرح ادعا</span>
            <textarea
                v-model="form.claimDescription"
                class="input"
                rows="4"
                maxlength="5000"
                placeholder="حداقل ۱۰ نویسه"
            />
            <span v-if="fieldErrors.claim_description" class="field__error">{{ fieldErrors.claim_description }}</span>
        </label>

        <p class="page__note">
            با ثبت اختلاف، مبلغ مورد ادعا نزد طرف مقابل مسدود می‌شود و او مهلت پاسخ خواهد داشت.
            ثبت ادعای بی‌اساس در سابقه شما ثبت می‌شود.
        </p>

        <p v-if="submitError" class="order-form__error">{{ submitError }}</p>

        <button
            class="btn btn--primary btn--block"
            type="button"
            :disabled="! isValid || submitting"
            @click="submit"
        >
            <span v-if="submitting">در حال ثبت…</span>
            <span v-else>ثبت اختلاف</span>
        </button>
    </section>
</template>

<script setup>
/**
 * Opening a dispute — §13.6 مرحله ۱, `POST /disputes` (🔑).
 *
 * IDEMPOTENCY. The route carries the `idempotency` middleware and opening a
 * case FREEZES money on the respondent's side, so a double submit would open
 * two cases and lock the amount twice. The key is minted when the form opens
 * and reused for every retry; a 409 IDEMPOTENCY_IN_PROGRESS keeps it, a 422
 * rotates it because the corrected claim is a different case.
 *
 * WHAT THE CLAIMANT MAY STATE. `claim_rial` is only offered for the categories
 * with nothing derivable — OpenDisputeRequest ignores it for a purity or weight
 * dispute, where the domain computes the disputed amount from the trade and the
 * alleged real figure. Offering the field there would invite a member to type a
 * number that is then silently discarded, and to believe they had frozen it.
 *
 * `respondent_org_id` disappears once a trade is named, for the same reason:
 * with a trade the respondent is whoever was on the other side of it, and the
 * claimant does not get to nominate somebody else.
 */
import { computed, onMounted, ref } from 'vue';

import NumberField from '../Common/NumberField.vue';
import { enumOptions, loadEnums } from '../../Stores/enums.js';
import { notify, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const emit = defineEmits(['opened', 'close']);

const api = useApi();

const submitting = ref(false);
const submitError = ref('');
const fieldErrors = ref({});

const blank = () => ({
    disputeType: 'PAYMENT_NOT_RECEIVED',
    tradeId: null,
    settlementId: null,
    respondentOrgId: null,
    actualPurityX10k: null,
    actualFineMg: null,
    claimRial: null,
    claimDescription: '',
});

const form = ref(blank());

/** §1.10 — minted when the form opens, reused across retries. */
const idempotencyKey = ref(uuid());

const disputeTypes = computed(() => {
    const options = enumOptions('dispute_type');
    // A catalogue that failed to load must not leave an empty select; the two
    // most common categories keep the form usable.
    return options.length > 0 ? options : [
        { value: 'PAYMENT_NOT_RECEIVED', label: 'عدم دریافت وجه' },
        { value: 'DELIVERY_NOT_MADE', label: 'عدم تحویل' },
    ];
});

/** The categories where the domain cannot derive the amount from a trade. */
const claimAmountApplies = computed(
    () => ! ['PURITY_MISMATCH', 'WEIGHT_MISMATCH'].includes(form.value.disputeType),
);

const isValid = computed(() => form.value.claimDescription.trim().length >= 10
    && (form.value.tradeId !== null || form.value.respondentOrgId !== null)
    && (form.value.disputeType !== 'PURITY_MISMATCH' || form.value.actualPurityX10k !== null)
    && (form.value.disputeType !== 'WEIGHT_MISMATCH' || form.value.actualFineMg !== null));

onMounted(() => void loadEnums());

function reset() {
    form.value = blank();
    submitError.value = '';
    fieldErrors.value = {};
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
        dispute_type: form.value.disputeType,
        claim_description: form.value.claimDescription.trim(),
    };

    if (form.value.tradeId) {
        body.trade_id = form.value.tradeId;
    } else if (form.value.respondentOrgId) {
        body.respondent_org_id = form.value.respondentOrgId;
    }
    if (form.value.settlementId) {
        body.settlement_id = form.value.settlementId;
    }
    if (form.value.actualPurityX10k !== null) {
        body.actual_purity_x10k = form.value.actualPurityX10k;
    }
    if (form.value.actualFineMg !== null) {
        body.actual_fine_mg = Number(form.value.actualFineMg);
    }
    if (claimAmountApplies.value && form.value.claimRial !== null) {
        body.claim_rial = Number(form.value.claimRial);
    }

    try {
        const { data, replayed } = await api.post('/disputes', body, {
            idempotencyKey: idempotencyKey.value,
        });

        notify(
            replayed
                ? 'این اختلاف قبلاً ثبت شده بود؛ همان پرونده بازگردانده شد.'
                : `پرونده ${data && data.case_number ? data.case_number : ''} ثبت شد.`,
            'success',
        );

        emit('opened', data);
        reset();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            submitError.value = 'درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.';
        } else {
            submitError.value = error.message || 'ثبت اختلاف ناموفق بود.';
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
