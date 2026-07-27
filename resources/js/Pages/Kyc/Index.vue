<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>احراز هویت</h1>
            <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
            <button class="btn btn--ghost btn--sm" type="button" @click="reload">تازه‌سازی</button>
        </header>

        <section class="panel-card">
            <header class="panel-card__header">
                <h2>وضعیت پرونده</h2>
                <StatusBadge :status="profile ? profile.status : null" :map="KYC_STATUS" />
            </header>

            <p v-if="profile === null" class="muted">در حال بارگذاری…</p>

            <template v-else>
                <div class="reconciliation" :class="`reconciliation--${bannerTone}`">
                    {{ guidance.message }}
                </div>

                <dl class="kv">
                    <div><dt>دفعات ارسال</dt><dd class="num" dir="ltr">{{ profile.submission_count }}</dd></div>
                    <div><dt>آخرین ارسال</dt><dd><JalaliCell :iso="profile.submitted_at" /></dd></div>
                    <div><dt>تأیید</dt><dd><JalaliCell :iso="profile.approved_at" /></dd></div>
                    <div><dt>بازبینی بعدی</dt><dd><JalaliCell :iso="profile.next_review_due_at" :with-time="false" /></dd></div>
                </dl>

                <h3>موارد باقی‌مانده</h3>
                <ul v-if="checklist.length" class="checklist">
                    <li v-for="item in checklist" :key="item.code" class="checklist__item">
                        <span class="checklist__mark">✗</span>
                        {{ item.label }}
                    </li>
                </ul>
                <p v-else class="muted">همه موارد لازم تکمیل شده است.</p>

                <button
                    class="btn btn--primary"
                    type="button"
                    :disabled="! guidance.canSubmit || submitting"
                    @click="submit"
                >
                    {{ profile.submission_count > 0 ? 'ارسال مجدد پرونده' : 'ارسال پرونده برای بررسی' }}
                </button>
            </template>
        </section>

        <!-- Documents -->
        <section class="panel-card">
            <header class="panel-card__header"><h2>مدارک</h2></header>

            <table class="table table--dense">
                <thead>
                    <tr>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>نام فایل</th>
                        <th class="align-end">حجم</th>
                        <th>بارگذاری</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="documents.length === 0">
                        <td colspan="6" class="table__empty">مدرکی بارگذاری نشده است.</td>
                    </tr>
                    <tr v-for="doc in documents" v-else :key="doc.id">
                        <td>{{ doc.type_label || enumLabel('document_type', doc.type) }}</td>
                        <td><StatusBadge :status="doc.status" :map="DOC_STATUS" /></td>
                        <td dir="ltr">{{ doc.original_filename || '—' }}</td>
                        <td class="align-end"><NumCell :value="kilobytes(doc.size_bytes)" /></td>
                        <td><JalaliCell :iso="doc.uploaded_at" /></td>
                        <td>
                            <div class="row-actions">
                                <a
                                    v-if="doc.download_url"
                                    class="btn btn--sm btn--ghost"
                                    :href="doc.download_url"
                                    target="_blank"
                                    rel="noopener"
                                >مشاهده</a>
                                <button
                                    v-if="doc.is_deletable && guidance.canEdit"
                                    class="btn btn--sm btn--ghost"
                                    type="button"
                                    @click="removeDocument(doc)"
                                >حذف</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="guidance.canEdit" class="panel-card__section">
                <h3>بارگذاری مدرک</h3>

                <label class="field">
                    <span class="field__label">نوع مدرک</span>
                    <select v-model="upload.type" class="input">
                        <option v-for="option in documentTypes" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="field">
                    <span class="field__label">فایل</span>
                    <input ref="fileInput" class="input" type="file" accept=".jpg,.jpeg,.png,.pdf" @change="onFile">
                    <span class="field__hint">JPEG، PNG یا PDF.</span>
                </label>

                <p v-if="uploadError" class="order-form__error">{{ uploadError }}</p>

                <button
                    class="btn btn--primary btn--sm"
                    type="button"
                    :disabled="upload.file === null || uploading"
                    @click="uploadDocument"
                >
                    <span v-if="uploading">در حال بارگذاری…</span>
                    <span v-else>بارگذاری</span>
                </button>
            </div>
        </section>

        <!-- Licences -->
        <section class="panel-card">
            <header class="panel-card__header"><h2>جواز کسب</h2></header>

            <table class="table table--dense">
                <thead>
                    <tr>
                        <th>شماره</th>
                        <th>اتحادیه</th>
                        <th>وضعیت</th>
                        <th>انقضا</th>
                        <th class="align-end">روز تا انقضا</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="licenses.length === 0">
                        <td colspan="5" class="table__empty">جوازی ثبت نشده است.</td>
                    </tr>
                    <tr
                        v-for="license in licenses"
                        v-else
                        :key="license.id"
                        :class="license.is_expired ? 'row--danger' : null"
                    >
                        <td dir="ltr">{{ license.license_no }}</td>
                        <td>{{ license.issuing_union }}</td>
                        <td><StatusBadge :status="license.status" /></td>
                        <td><JalaliCell :iso="license.expires_at" :with-time="false" /></td>
                        <td class="align-end"><NumCell :value="license.days_until_expiry" /></td>
                    </tr>
                </tbody>
            </table>

            <div v-if="guidance.canEdit" class="panel-card__section">
                <h3>ثبت جواز</h3>
                <label class="field">
                    <span class="field__label">شماره جواز</span>
                    <input v-model="license.licenseNo" class="input" type="text" maxlength="64">
                </label>
                <label class="field">
                    <span class="field__label">اتحادیه صادرکننده</span>
                    <input v-model="license.issuingUnion" class="input" type="text" maxlength="191">
                </label>
                <label class="field">
                    <span class="field__label">تاریخ صدور (میلادی)</span>
                    <input v-model="license.issuedAt" class="input num" dir="ltr" type="date">
                </label>
                <label class="field">
                    <span class="field__label">تاریخ انقضا (میلادی)</span>
                    <input v-model="license.expiresAt" class="input num" dir="ltr" type="date">
                </label>
                <p v-if="licenseError" class="order-form__error">{{ licenseError }}</p>
                <button class="btn btn--primary btn--sm" type="button" :disabled="! licenseValid" @click="addLicense">
                    ثبت جواز
                </button>
            </div>
        </section>

        <!-- Bank accounts -->
        <section class="panel-card">
            <header class="panel-card__header"><h2>حساب‌های بانکی</h2></header>

            <table class="table table--dense">
                <thead>
                    <tr>
                        <th>شبا</th>
                        <th>بانک</th>
                        <th>صاحب حساب</th>
                        <th>وضعیت</th>
                        <th>اصلی</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="bankAccounts.length === 0">
                        <td colspan="5" class="table__empty">حسابی ثبت نشده است.</td>
                    </tr>
                    <tr v-for="account in bankAccounts" v-else :key="account.id">
                        <td class="num" dir="ltr">{{ account.iban_masked }}</td>
                        <td>{{ account.bank_name }}</td>
                        <td>{{ account.account_holder_name }}</td>
                        <td><StatusBadge :status="account.status" /></td>
                        <td>{{ account.is_primary ? 'بله' : '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="guidance.canEdit" class="panel-card__section">
                <h3>افزودن حساب</h3>
                <label class="field">
                    <span class="field__label">شماره شبا</span>
                    <input v-model="bank.iban" class="input num" dir="ltr" type="text" maxlength="34" placeholder="IR…">
                </label>
                <label class="field">
                    <span class="field__label">نام بانک</span>
                    <input v-model="bank.bankName" class="input" type="text" maxlength="100">
                </label>
                <label class="field">
                    <span class="field__label">نام صاحب حساب</span>
                    <input v-model="bank.accountHolderName" class="input" type="text" maxlength="191">
                </label>
                <p class="muted">ثبت حساب بانکی نیاز به تأیید تراکنش (کد یکبارمصرف) دارد.</p>
                <p v-if="bankError" class="order-form__error">{{ bankError }}</p>
                <button class="btn btn--primary btn--sm" type="button" :disabled="! bankValid" @click="addBankAccount">
                    ثبت حساب ✍️
                </button>
            </div>
        </section>
    </div>
</template>

<script setup>
/**
 * KYC — the member's own dossier, `GET /organization/kyc` and §2.2.
 *
 * WHAT «respond to an information request» MEANS HERE. `INFO_REQUIRED` is a
 * real KycStatus and the dossier becomes editable again in it
 * (KycStatus::isEditableByMember). What the API does NOT offer is the officer's
 * request itself — `KycProfileResource` deliberately withholds
 * `last_decision_note`, which it says is «written for compliance, not for
 * them» — nor any endpoint that carries a written reply back. So responding is
 * expressed as the platform models it: the outstanding items are listed, the
 * member fixes them, and `POST /organization/kyc/submit` resubmits. The gap is
 * named in the report rather than papered over with an invented field.
 *
 * EDITABILITY IS READ FROM THE SERVER, not guessed. Upload, licence and bank
 * forms only appear while `is_editable` is true, because every one of those
 * writes is refused on a dossier sitting in the compliance queue and a form
 * that submits into a refusal is worse than no form.
 *
 * The bank-account write is ✍️ `transaction.sign`; the button says so, and an
 * AUTH_TRANSACTION_SIGN_REQUIRED response is reported as the second factor it
 * is rather than as an error.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import JalaliCell from '../../Components/Data/JalaliCell.vue';
import NumCell from '../../Components/Data/NumCell.vue';
import StaleBadge from '../../Components/Common/StaleBadge.vue';
import StatusBadge from '../../Components/Data/StatusBadge.vue';
import { describeChecklist, statusGuidance } from '../../lib/kyc-checklist.js';
import { enumLabel, enumOptions, loadEnums } from '../../Stores/enums.js';
import { notify, panel, useApi } from '../../Stores/panel.js';

const api = useApi();

const KYC_STATUS = {
    DRAFT: 'پیش‌نویس',
    SUBMITTED: 'ارسال شده',
    IN_REVIEW: 'در حال بررسی',
    INFO_REQUIRED: 'نیازمند اطلاعات تکمیلی',
    APPROVED: 'تأیید شده',
    REJECTED: 'رد شده',
};

const DOC_STATUS = {
    PENDING: 'در انتظار بررسی',
    VERIFIED: 'تأیید شده',
    REJECTED: 'رد شده',
    EXPIRED: 'منقضی',
};

const profile = ref(null);
const documents = ref([]);
const licenses = ref([]);
const bankAccounts = ref([]);

const ageMs = ref(Infinity);
const staleAfterMs = panel.realtime.stale_after_ms || 30000;
let lastUpdate = null;
let ageTimer = null;

const submitting = ref(false);

const fileInput = ref(null);
const upload = ref({ type: 'NATIONAL_CARD_FRONT', file: null });
const uploading = ref(false);
const uploadError = ref('');

const license = ref({ licenseNo: '', issuingUnion: '', issuedAt: '', expiresAt: '' });
const licenseError = ref('');

const bank = ref({ iban: '', bankName: '', accountHolderName: '' });
const bankError = ref('');

const guidance = computed(() => statusGuidance(profile.value));

const bannerTone = computed(() => ({
    good: 'ok',
    info: 'ok',
    warn: 'partial',
    danger: 'break',
    muted: 'partial',
}[guidance.value.tone] || 'partial'));

const checklist = computed(() => describeChecklist(
    profile.value ? profile.value.missing_items : [],
    (type) => enumLabel('document_type', type),
));

const documentTypes = computed(() => {
    const options = enumOptions('document_type');
    return options.length > 0 ? options : [{ value: 'OTHER', label: 'سایر' }];
});

const licenseValid = computed(() => license.value.licenseNo !== ''
    && license.value.issuingUnion !== ''
    && license.value.issuedAt !== ''
    && license.value.expiresAt !== '');

const bankValid = computed(() => bank.value.iban !== ''
    && bank.value.bankName !== ''
    && bank.value.accountHolderName !== '');

const kilobytes = (bytes) => (bytes ? Math.ceil(Number(bytes) / 1024) : null);

/**
 * A failed section leaves its previous contents on screen: a dossier page that
 * blanked the document list because the licence call timed out would look like
 * a member who has uploaded nothing.
 */
async function reload() {
    const results = await Promise.allSettled([
        api.get('/organization/kyc'),
        api.get('/organization/documents'),
        api.get('/organization/licenses'),
        api.get('/organization/bank-accounts'),
    ]);

    const [kyc, docs, lics, banks] = results;

    if (kyc.status === 'fulfilled') {
        profile.value = kyc.value.data;
    }
    if (docs.status === 'fulfilled') {
        documents.value = Array.isArray(docs.value.data) ? docs.value.data : [];
    }
    if (lics.status === 'fulfilled') {
        licenses.value = Array.isArray(lics.value.data) ? lics.value.data : [];
    }
    if (banks.status === 'fulfilled') {
        bankAccounts.value = Array.isArray(banks.value.data) ? banks.value.data : [];
    }

    // The age only resets when the dossier itself came back; a stale dossier
    // beside a fresh document list would be the confusing half-truth.
    if (kyc.status === 'fulfilled') {
        lastUpdate = Date.now();
        ageMs.value = 0;
    }
}

onMounted(() => {
    void loadEnums();
    void reload();

    ageTimer = setInterval(() => {
        ageMs.value = lastUpdate === null ? Infinity : Date.now() - lastUpdate;
    }, 1000);
});

onBeforeUnmount(() => clearInterval(ageTimer));

async function submit() {
    submitting.value = true;

    try {
        const { data } = await api.post('/organization/kyc/submit', {});
        profile.value = data;
        notify('پرونده برای بررسی ارسال شد.', 'success');
    } catch (error) {
        if (error.code === 'KYC_INCOMPLETE') {
            notify('پرونده هنوز کامل نیست؛ موارد باقی‌مانده را ببینید.', 'warn');
        } else {
            notify(error.message || 'ارسال پرونده ناموفق بود.', 'error');
        }
    } finally {
        submitting.value = false;
        void reload();
    }
}

function onFile(event) {
    upload.value.file = (event.target.files && event.target.files[0]) || null;
}

async function uploadDocument() {
    if (upload.value.file === null || uploading.value) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    const form = new FormData();
    form.append('type', upload.value.type);
    form.append('file', upload.value.file);

    try {
        await api.upload('/organization/documents', form);
        notify('مدرک بارگذاری شد.', 'success');
        upload.value.file = null;
        if (fileInput.value) {
            fileInput.value.value = '';
        }
        void reload();
    } catch (error) {
        uploadError.value = firstFieldError(error) || error.message || 'بارگذاری ناموفق بود.';
    } finally {
        uploading.value = false;
    }
}

async function removeDocument(doc) {
    try {
        await api.delete(`/organization/documents/${doc.id}`);
        notify('مدرک حذف شد.', 'success');
    } catch (error) {
        notify(error.message || 'حذف مدرک ناموفق بود.', 'error');
    } finally {
        void reload();
    }
}

async function addLicense() {
    licenseError.value = '';

    try {
        await api.post('/organization/licenses', {
            license_no: license.value.licenseNo,
            issuing_union: license.value.issuingUnion,
            issued_at: license.value.issuedAt,
            expires_at: license.value.expiresAt,
        });
        notify('جواز ثبت شد.', 'success');
        license.value = { licenseNo: '', issuingUnion: '', issuedAt: '', expiresAt: '' };
        void reload();
    } catch (error) {
        licenseError.value = firstFieldError(error) || error.message || 'ثبت جواز ناموفق بود.';
    }
}

async function addBankAccount() {
    bankError.value = '';

    try {
        await api.post('/organization/bank-accounts', {
            iban: bank.value.iban,
            bank_name: bank.value.bankName,
            account_holder_name: bank.value.accountHolderName,
        });
        notify('حساب بانکی ثبت شد.', 'success');
        bank.value = { iban: '', bankName: '', accountHolderName: '' };
        void reload();
    } catch (error) {
        if (error.code === 'AUTH_TRANSACTION_SIGN_REQUIRED') {
            bankError.value = 'ثبت حساب بانکی نیاز به تأیید تراکنش دارد؛ کد یکبارمصرف را وارد کنید.';
            return;
        }
        bankError.value = firstFieldError(error) || error.message || 'ثبت حساب ناموفق بود.';
    }
}

/** The first per-field message, which is more actionable than the envelope's. */
function firstFieldError(error) {
    if (! error || ! error.fieldErrors) {
        return null;
    }
    const first = Object.values(error.fieldErrors)[0];
    return Array.isArray(first) ? first[0] : null;
}
</script>
