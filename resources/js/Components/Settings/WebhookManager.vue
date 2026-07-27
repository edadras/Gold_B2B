<template>
    <section class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>Webhookها</h2>
            <button class="btn btn--primary btn--sm" type="button" @click="registering = ! registering">
                {{ registering ? 'بستن فرم' : 'ثبت Webhook' }}
            </button>
        </header>

        <p v-if="issuedSecret" class="reconciliation reconciliation--partial">
            {{ issuedWarning }}
            <br>
            <span class="num" dir="ltr">{{ issuedSecret }}</span>
            <button class="btn btn--ghost btn--sm" type="button" @click="issuedSecret = null">فهمیدم</button>
        </p>

        <div v-if="registering" class="panel-card__section">
            <label class="field">
                <span class="field__label">نشانی مقصد</span>
                <input v-model="form.url" class="input" dir="ltr" type="text" maxlength="500" placeholder="https://…">
                <span class="field__hint">فقط نشانی HTTPS عمومی پذیرفته می‌شود.</span>
                <span v-if="fieldErrors.url" class="field__error">{{ fieldErrors.url }}</span>
            </label>

            <label class="field">
                <span class="field__label">توضیح (اختیاری)</span>
                <input v-model="form.description" class="input" type="text" maxlength="255">
            </label>

            <fieldset class="field">
                <legend class="field__label">رویدادها</legend>
                <details v-for="group in eventGroups" :key="group.group" class="event-group">
                    <summary dir="ltr">{{ group.group }} ({{ group.options.length }})</summary>
                    <label v-for="option in group.options" :key="option.value" class="field field--inline">
                        <input
                            type="checkbox"
                            :checked="form.events.includes(option.value)"
                            @change="toggleEvent(option.value)"
                        >
                        <span class="num" dir="ltr">{{ option.value }}</span>
                        <span class="muted">{{ option.label }}</span>
                    </label>
                </details>
                <span v-if="fieldErrors.events" class="field__error">{{ fieldErrors.events }}</span>
            </fieldset>

            <p v-if="formError" class="order-form__error">{{ formError }}</p>

            <button
                class="btn btn--primary btn--sm"
                type="button"
                :disabled="form.url === '' || form.events.length === 0 || saving"
                @click="register"
            >ثبت</button>
        </div>

        <table class="table table--dense">
            <thead>
                <tr>
                    <th>نشانی</th>
                    <th>وضعیت</th>
                    <th class="align-end">رویدادها</th>
                    <th class="align-end">خطای پیاپی</th>
                    <th>آخرین موفقیت</th>
                    <th>کلید</th>
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr v-if="webhooks.length === 0">
                    <td colspan="7" class="table__empty">Webhookی ثبت نشده است.</td>
                </tr>
                <tr
                    v-for="hook in webhooks"
                    v-else
                    :key="hook.id"
                    :class="hook.consecutive_failures > 0 ? 'row--danger' : null"
                >
                    <td dir="ltr" class="webhook__url">{{ hook.url }}</td>
                    <td><StatusBadge :status="hook.status" :map="HOOK_STATUS" /></td>
                    <td class="align-end"><NumCell :value="hook.events.length" /></td>
                    <td class="align-end"><NumCell :value="hook.consecutive_failures" /></td>
                    <td><JalaliCell :iso="hook.last_success_at" /></td>
                    <td class="num" dir="ltr">…{{ hook.secret_last_four }}</td>
                    <td>
                        <div class="row-actions">
                            <button class="btn btn--sm btn--ghost" type="button" @click="test(hook)">آزمایش</button>
                            <button class="btn btn--sm btn--ghost" type="button" @click="showDeliveries(hook)">تاریخچه</button>
                            <button class="btn btn--sm btn--ghost" type="button" @click="rotating = hook">چرخش کلید</button>
                            <button class="btn btn--sm btn--ghost" type="button" @click="removing = hook">حذف</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="lastError" class="page__note">آخرین خطا: <span dir="ltr">{{ lastError }}</span></p>

        <div v-if="deliveries !== null" class="panel-card__section">
            <h3>تاریخچه ارسال</h3>
            <table class="table table--dense">
                <thead>
                    <tr>
                        <th>رویداد</th>
                        <th>وضعیت</th>
                        <th class="align-end">کد پاسخ</th>
                        <th class="align-end">تلاش</th>
                        <th>زمان</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="deliveries.length === 0">
                        <td colspan="5" class="table__empty">ارسالی ثبت نشده است.</td>
                    </tr>
                    <tr v-for="delivery in deliveries" v-else :key="delivery.id">
                        <td class="num" dir="ltr">{{ delivery.event_type }}</td>
                        <td><StatusBadge :status="delivery.status" /></td>
                        <td class="align-end"><NumCell :value="delivery.response_code" /></td>
                        <td class="align-end"><NumCell :value="delivery.attempts" /></td>
                        <td><JalaliCell :iso="delivery.created_at" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="rotating !== null"
            title="چرخش کلید Webhook"
            confirm-label="چرخش کلید"
            @confirm="rotateSecret"
            @cancel="rotating = null"
        >
            <p>
                کلید فعلی بلافاصله باطل می‌شود و امضای رویدادها با کلید جدید محاسبه خواهد شد.
                تا زمانی که کلید جدید را در سامانه مقصد ثبت نکنید، امضاها تأیید نمی‌شوند.
            </p>
        </ConfirmDialog>

        <ConfirmDialog
            :open="removing !== null"
            title="حذف Webhook"
            confirm-label="حذف"
            @confirm="remove"
            @cancel="removing = null"
        >
            <p v-if="removing">
                ارسال رویدادها به <span dir="ltr">{{ removing.url }}</span> متوقف می‌شود.
            </p>
        </ConfirmDialog>
    </section>
</template>

<script setup>
/**
 * Webhook management — the seven rows of §3.13.
 *
 * THE SECRET IS SHOWN ONCE, AND THE SCREEN SAYS SO. `POST /webhooks` and
 * `/rotate-secret` are the only two responses in the platform that carry a
 * plaintext secret; every other read renders `WebhookResource`, which has no
 * `secret` key at all. The banner therefore repeats the server's own warning
 * and stays up until the member dismisses it — auto-hiding it after a few
 * seconds, the way a toast would, is how somebody loses a credential they
 * cannot recover.
 *
 * EVENTS COME FROM `/meta/enums`. There are thirty-six of them; a hard-coded
 * list here would go short the first time one is added, and `events.*` is
 * validated against the server's own set, so a stale copy produces a rejection
 * rather than a missed event.
 *
 * A webhook is a credential — whoever holds the URL receives the member's trade
 * flow — so deletion and rotation both go through a confirmation that states
 * the consequence rather than asking «are you sure».
 */
import { computed, onMounted, ref } from 'vue';

import ConfirmDialog from '../Common/ConfirmDialog.vue';
import JalaliCell from '../Data/JalaliCell.vue';
import NumCell from '../Data/NumCell.vue';
import StatusBadge from '../Data/StatusBadge.vue';
import { enumOptions, loadEnums } from '../../Stores/enums.js';
import { groupByPrefix } from '../../lib/enum-catalogue.js';
import { notify, useApi } from '../../Stores/panel.js';

const api = useApi();

const HOOK_STATUS = {
    ACTIVE: 'فعال',
    PAUSED: 'متوقف',
    DISABLED: 'غیرفعال',
};

const webhooks = ref([]);
const deliveries = ref(null);
const registering = ref(false);
const saving = ref(false);
const formError = ref('');
const fieldErrors = ref({});
const form = ref({ url: '', description: '', events: [] });

const issuedSecret = ref(null);
const issuedWarning = ref('');
const lastError = ref('');

const rotating = ref(null);
const removing = ref(null);

const eventGroups = computed(() => groupByPrefix(enumOptions('webhook_event_type')));

async function reload() {
    try {
        const { data } = await api.get('/webhooks');
        webhooks.value = Array.isArray(data) ? data : [];
        const failing = webhooks.value.find((hook) => hook.last_error);
        lastError.value = failing ? failing.last_error : '';
    } catch (error) {
        notify(error.message || 'دریافت فهرست Webhookها ناموفق بود.', 'error');
    }
}

onMounted(() => {
    void loadEnums();
    void reload();
});

function toggleEvent(event) {
    form.value.events = form.value.events.includes(event)
        ? form.value.events.filter((value) => value !== event)
        : [...form.value.events, event];
}

async function register() {
    if (saving.value) {
        return;
    }

    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};

    const body = { url: form.value.url.trim(), events: form.value.events };

    if (form.value.description.trim() !== '') {
        body.description = form.value.description.trim();
    }

    try {
        const { data, meta } = await api.post('/webhooks', body);

        issuedSecret.value = data && data.secret ? data.secret : null;
        issuedWarning.value = (meta && meta.warning) || 'این تنها بار نمایش secret است. آن را ذخیره کنید.';

        registering.value = false;
        form.value = { url: '', description: '', events: [] };
        void reload();
    } catch (error) {
        formError.value = error.message || 'ثبت Webhook ناموفق بود.';
        fieldErrors.value = error.fieldErrors
            ? Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]]))
            : {};
    } finally {
        saving.value = false;
    }
}

async function rotateSecret() {
    const hook = rotating.value;
    rotating.value = null;

    if (hook === null) {
        return;
    }

    try {
        const { data, meta } = await api.post(`/webhooks/${hook.id}/rotate-secret`, {});
        issuedSecret.value = data && data.secret ? data.secret : null;
        issuedWarning.value = (meta && meta.warning)
            || 'این تنها بار نمایش secret جدید است. کلید قبلی دیگر معتبر نیست.';
        void reload();
    } catch (error) {
        notify(error.message || 'چرخش کلید ناموفق بود.', 'error');
    }
}

async function test(hook) {
    try {
        await api.post(`/webhooks/${hook.id}/test`, {});
        notify('رویداد آزمایشی در صف ارسال قرار گرفت.', 'info');
    } catch (error) {
        notify(error.message || 'ارسال رویداد آزمایشی ناموفق بود.', 'error');
    }
}

async function showDeliveries(hook) {
    try {
        const { data } = await api.get(`/webhooks/${hook.id}/deliveries`);
        deliveries.value = Array.isArray(data) ? data : [];
    } catch (error) {
        notify(error.message || 'دریافت تاریخچه ناموفق بود.', 'error');
    }
}

async function remove() {
    const hook = removing.value;
    removing.value = null;

    if (hook === null) {
        return;
    }

    try {
        await api.delete(`/webhooks/${hook.id}`);
        notify('Webhook حذف شد.', 'success');
        deliveries.value = null;
    } catch (error) {
        notify(error.message || 'حذف ناموفق بود.', 'error');
    } finally {
        void reload();
    }
}
</script>
