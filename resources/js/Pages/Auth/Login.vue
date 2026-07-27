<template>
    <div class="login" dir="rtl">
        <form class="login__card" @submit.prevent="submit">
            <h1 class="login__brand">Gold B2B</h1>
            <p class="login__subtitle">پنل معامله‌گر</p>

            <template v-if="stage === 'credentials'">
                <label class="field">
                    <span class="field__label">شماره موبایل</span>
                    <input
                        ref="mobileField"
                        v-model="mobile"
                        class="input num"
                        dir="ltr"
                        type="tel"
                        autocomplete="username"
                        placeholder="09123456789"
                        @input="normaliseMobile"
                    >
                </label>

                <label class="field">
                    <span class="field__label">رمز عبور</span>
                    <input v-model="password" class="input" type="password" autocomplete="current-password">
                </label>
            </template>

            <template v-else>
                <p class="login__hint">
                    کد تأیید دو مرحله‌ای را وارد کنید
                    <span v-if="methods.length" class="muted">({{ methods.join(' / ') }})</span>
                </p>
                <label class="field">
                    <span class="field__label">کد</span>
                    <input
                        ref="codeField"
                        v-model="code"
                        class="input num"
                        dir="ltr"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="8"
                        @input="normaliseCode"
                    >
                </label>
            </template>

            <p v-if="error" class="login__error">{{ error }}</p>

            <button class="btn btn--primary btn--block" type="submit" :disabled="busy">
                <span v-if="busy">…</span>
                <span v-else-if="stage === 'credentials'">ورود</span>
                <span v-else>تأیید</span>
            </button>

            <button
                v-if="stage === 'two-factor'"
                class="btn btn--ghost btn--block"
                type="button"
                @click="reset"
            >بازگشت</button>
        </form>
    </div>
</template>

<script setup>
/**
 * Sign-in — §2.1's two-step flow, driven entirely from the client.
 *
 * The panel does NOT have its own login endpoint: it posts to
 * `/api/v1/auth/login` exactly like the mobile client, so lockout counting, the
 * TOTP challenge and session bookkeeping all happen once, in Identity. The
 * resulting access token is then handed to `POST /app/session`, which opens the
 * browser session that protects `/app/*`.
 *
 * Persian digits are normalised in the mobile and code fields, because a member
 * typing on a Persian keyboard produces «۰۹۱۲…» and the API expects ASCII.
 */
import { nextTick, onMounted, ref } from 'vue';

import { ApiClient } from '../../lib/api.js';
import { normalizeDigits } from '../../lib/numeric-input.js';

const props = defineProps({
    csrfToken: { type: String, default: '' },
});

const api = new ApiClient({ baseUrl: '/api/v1', csrfToken: props.csrfToken });

const stage = ref('credentials');
const mobile = ref('');
const password = ref('');
const code = ref('');
const methods = ref([]);
const challengeToken = ref(null);
const error = ref('');
const busy = ref(false);
const mobileField = ref(null);
const codeField = ref(null);

onMounted(() => {
    if (mobileField.value) {
        mobileField.value.focus();
    }
});

function normaliseMobile(event) {
    mobile.value = normalizeDigits(event.target.value).replace(/[^\d+]/g, '');
    event.target.value = mobile.value;
}

function normaliseCode(event) {
    code.value = normalizeDigits(event.target.value).replace(/\D/g, '');
    event.target.value = code.value;
}

function reset() {
    stage.value = 'credentials';
    challengeToken.value = null;
    code.value = '';
    error.value = '';
}

async function establishSession(accessToken) {
    await api.panel('POST', '/app/session', { access_token: accessToken });
    window.location.assign('/app/terminal');
}

async function submit() {
    if (busy.value) {
        return;
    }
    busy.value = true;
    error.value = '';

    try {
        if (stage.value === 'credentials') {
            const { data } = await api.post('/auth/login', {
                mobile: mobile.value,
                password: password.value,
            });

            if (data && data.requires_2fa) {
                challengeToken.value = data.challenge_token;
                methods.value = data.methods || [];
                stage.value = 'two-factor';
                await nextTick();
                if (codeField.value) {
                    codeField.value.focus();
                }
                return;
            }

            await establishSession(data.access_token);
            return;
        }

        const { data } = await api.post('/auth/login/2fa', {
            challenge_token: challengeToken.value,
            method: methods.value[0] || 'TOTP',
            code: code.value,
        });

        await establishSession(data.access_token);
    } catch (caught) {
        error.value = caught.message || 'ورود ناموفق بود.';
        if (caught.code === 'AUTH_2FA_INVALID') {
            code.value = '';
        }
    } finally {
        busy.value = false;
    }
}
</script>
