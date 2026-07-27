<template>
    <div dir="rtl">
        <section class="panel-card">
            <header class="panel-card__header"><h2>حساب کاربری</h2></header>

            <dl v-if="me" class="kv">
                <div><dt>نام</dt><dd>{{ me.user.full_name }}</dd></div>
                <div><dt>موبایل</dt><dd class="num" dir="ltr">{{ me.user.mobile }}</dd></div>
                <div><dt>ایمیل</dt><dd dir="ltr">{{ me.user.email || '—' }}</dd></div>
                <div><dt>نقش‌ها</dt><dd>{{ (me.user.role_labels || []).join('، ') }}</dd></div>
                <div><dt>ورود دوعاملی</dt><dd>{{ me.user.two_factor_enabled ? 'فعال' : 'غیرفعال' }}</dd></div>
                <div><dt>آخرین ورود</dt><dd><JalaliCell :iso="me.user.last_login_at" /></dd></div>
            </dl>
            <p v-else class="muted">در حال بارگذاری…</p>
        </section>

        <section class="panel-card">
            <header class="panel-card__header"><h2>تغییر رمز عبور</h2></header>

            <label class="field">
                <span class="field__label">رمز فعلی</span>
                <input v-model="password.current" class="input" dir="ltr" type="password" autocomplete="current-password">
            </label>
            <label class="field">
                <span class="field__label">رمز جدید</span>
                <input v-model="password.next" class="input" dir="ltr" type="password" autocomplete="new-password">
            </label>
            <label class="field">
                <span class="field__label">تکرار رمز جدید</span>
                <input v-model="password.confirmation" class="input" dir="ltr" type="password" autocomplete="new-password">
            </label>

            <p class="page__note">
                با تغییر رمز، همه نشست‌های فعال شما — از جمله همین نشست — بسته می‌شود و باید
                دوباره وارد شوید.
            </p>

            <p v-if="passwordError" class="order-form__error">{{ passwordError }}</p>

            <button
                class="btn btn--primary btn--sm"
                type="button"
                :disabled="! passwordValid || changingPassword"
                @click="changePassword"
            >تغییر رمز</button>
        </section>

        <section class="panel-card">
            <header class="panel-card__header">
                <h2>نشست‌های فعال</h2>
                <button class="btn btn--ghost btn--sm" type="button" @click="loadSessions">تازه‌سازی</button>
            </header>

            <table class="table table--dense">
                <thead>
                    <tr>
                        <th>دستگاه</th>
                        <th>نشانی IP</th>
                        <th>مکان</th>
                        <th>آخرین فعالیت</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="sessions.length === 0">
                        <td colspan="5" class="table__empty">نشستی یافت نشد.</td>
                    </tr>
                    <tr
                        v-for="session in sessions"
                        v-else
                        :key="session.id"
                        :class="session.is_current ? 'row--attention' : null"
                    >
                        <td dir="ltr">{{ session.user_agent || session.device_id || '—' }}</td>
                        <td class="num" dir="ltr">{{ session.ip_address || '—' }}</td>
                        <td>{{ session.geo_city || session.geo_country || '—' }}</td>
                        <td><JalaliCell :iso="session.last_seen_at" /></td>
                        <td>
                            <button
                                v-if="! session.is_current && session.is_active"
                                class="btn btn--sm btn--ghost"
                                type="button"
                                @click="revoke(session)"
                            >پایان نشست</button>
                            <span v-else-if="session.is_current" class="muted">نشست فعلی</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="panel-card">
            <header class="panel-card__header"><h2>مشخصات سازمان</h2></header>

            <template v-if="organization">
                <dl class="kv">
                    <div><dt>نام</dt><dd>{{ organization.display_name }}</dd></div>
                    <div><dt>نوع</dt><dd>{{ organization.type === 'INDIVIDUAL' ? 'حقیقی' : 'حقوقی' }}</dd></div>
                    <div><dt>وضعیت</dt><dd><StatusBadge :status="organization.status" :map="ORG_STATUS" /></dd></div>
                    <div><dt>شهر</dt><dd>{{ organization.city }}</dd></div>
                    <div><dt>سطح احراز</dt><dd>{{ organization.verification_tier || '—' }}</dd></div>
                </dl>

                <div v-if="canEditOrganization" class="panel-card__section">
                    <h3>ویرایش اطلاعات تماس</h3>
                    <label class="field">
                        <span class="field__label">نشانی</span>
                        <input v-model="orgForm.address" class="input" type="text" maxlength="500">
                    </label>
                    <label class="field">
                        <span class="field__label">کد پستی</span>
                        <input v-model="orgForm.postal_code" class="input num" dir="ltr" type="text" maxlength="10">
                    </label>
                    <label class="field">
                        <span class="field__label">تلفن</span>
                        <input v-model="orgForm.phone" class="input num" dir="ltr" type="tel" maxlength="20">
                    </label>
                    <label class="field">
                        <span class="field__label">ایمیل</span>
                        <input v-model="orgForm.email" class="input" dir="ltr" type="email" maxlength="191">
                    </label>
                    <label class="field">
                        <span class="field__label">وب‌سایت</span>
                        <input v-model="orgForm.website" class="input" dir="ltr" type="text" maxlength="191">
                    </label>

                    <p v-if="orgError" class="order-form__error">{{ orgError }}</p>

                    <button class="btn btn--primary btn--sm" type="button" :disabled="savingOrg" @click="saveOrganization">
                        ذخیره
                    </button>
                </div>
                <p v-else class="muted">
                    ویرایش مشخصات سازمان تنها برای مالک و مدیر در دسترس است.
                </p>
            </template>
            <p v-else class="muted">در حال بارگذاری…</p>
        </section>
    </div>
</template>

<script setup>
/**
 * Profile — the account half of the settings screen.
 *
 * WHAT IS DELIBERATELY ABSENT: an API-key manager. §2 exposes no personal
 * access-token endpoints; the panel's own bearer token is minted by
 * `/auth/login` and handed to the shell, and there is no route that lists,
 * creates or revokes a long-lived key. Rather than build a screen against an
 * invented shape, the gap is named in the report. Machine access to the API is
 * what the webhook tab covers, and that is a real endpoint.
 *
 * THE PASSWORD CHANGE IS DESTRUCTIVE TO THE SESSION and says so before it is
 * pressed: `AuthController::changePassword` calls `revokeAllSessions`, so the
 * member is signed out of this tab too. A member who discovers that afterwards
 * assumes something broke.
 *
 * The two-factor controls are read-only here: `/auth/2fa/enable` starts a TOTP
 * enrolment that needs a QR code and a confirmation step, and `DELETE /auth/2fa`
 * is ✍️ signed. Half an enrolment flow is worse than none, so the panel states
 * the current setting and leaves the flow out; noted in the report.
 */
import { computed, onMounted, ref } from 'vue';

import JalaliCell from '../Data/JalaliCell.vue';
import StatusBadge from '../Data/StatusBadge.vue';
import { can, notify, useApi } from '../../Stores/panel.js';

const api = useApi();

const ORG_STATUS = {
    ACTIVE: 'فعال',
    PENDING: 'در انتظار',
    UNDER_REVIEW: 'در حال بررسی',
    SUSPENDED: 'معلق',
    CLOSED: 'بسته',
};

const me = ref(null);
const organization = ref(null);
const sessions = ref([]);

const password = ref({ current: '', next: '', confirmation: '' });
const passwordError = ref('');
const changingPassword = ref(false);

const orgForm = ref({ address: '', postal_code: '', phone: '', email: '', website: '' });
const orgError = ref('');
const savingOrg = ref(false);

const canEditOrganization = computed(() => can('kyc.edit'));

const passwordValid = computed(() => password.value.current !== ''
    && password.value.next !== ''
    && password.value.next === password.value.confirmation);

async function loadMe() {
    try {
        const { data } = await api.get('/auth/me');
        me.value = data;
    } catch (error) {
        notify(error.message || 'دریافت اطلاعات حساب ناموفق بود.', 'error');
    }
}

async function loadOrganization() {
    try {
        const { data } = await api.get('/organization');
        organization.value = data;
        orgForm.value = {
            address: data.address || '',
            postal_code: data.postal_code || '',
            phone: data.phone || '',
            email: data.email || '',
            website: data.website || '',
        };
    } catch (error) {
        // A VIEWER may read this; a failure here must not blank the account
        // card above it, so the section simply keeps its loading line.
        notify(error.message || 'دریافت مشخصات سازمان ناموفق بود.', 'error');
    }
}

async function loadSessions() {
    try {
        const { data } = await api.get('/auth/sessions');
        sessions.value = Array.isArray(data) ? data : [];
    } catch (error) {
        notify(error.message || 'دریافت نشست‌ها ناموفق بود.', 'error');
    }
}

onMounted(() => {
    void loadMe();
    void loadOrganization();
    void loadSessions();
});

async function changePassword() {
    if (! passwordValid.value || changingPassword.value) {
        return;
    }

    changingPassword.value = true;
    passwordError.value = '';

    try {
        await api.put('/auth/password', {
            current_password: password.value.current,
            password: password.value.next,
            password_confirmation: password.value.confirmation,
        });

        notify('رمز عبور تغییر کرد؛ دوباره وارد شوید.', 'success');
        // Every session was revoked, this one included: staying on a panel
        // whose token is dead would show an error on the next call instead.
        window.location.assign('/app/login');
    } catch (error) {
        passwordError.value = error.code === 'AUTH_INVALID_CREDENTIALS'
            ? 'رمز عبور فعلی نادرست است.'
            : (error.message || 'تغییر رمز ناموفق بود.');
    } finally {
        changingPassword.value = false;
    }
}

async function revoke(session) {
    try {
        await api.delete(`/auth/sessions/${session.id}`);
        notify('نشست بسته شد.', 'success');
    } catch (error) {
        notify(error.message || 'بستن نشست ناموفق بود.', 'error');
    } finally {
        void loadSessions();
    }
}

async function saveOrganization() {
    savingOrg.value = true;
    orgError.value = '';

    // Empty means "clear it", which the rules express as null — an empty string
    // fails `size:10` on the postal code and `email` on the address.
    const body = Object.fromEntries(
        Object.entries(orgForm.value).map(([key, value]) => [key, value === '' ? null : value]),
    );

    try {
        const { data } = await api.put('/organization', body);
        organization.value = data;
        notify('مشخصات سازمان ذخیره شد.', 'success');
    } catch (error) {
        const first = error.fieldErrors ? Object.values(error.fieldErrors)[0] : null;
        orgError.value = (Array.isArray(first) ? first[0] : null)
            || error.message
            || 'ذخیره مشخصات ناموفق بود.';
    } finally {
        savingOrg.value = false;
    }
}
</script>
