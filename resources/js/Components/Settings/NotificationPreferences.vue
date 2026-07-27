<template>
    <section class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>اعلان‌ها</h2>
            <button class="btn btn--ghost btn--sm" type="button" :disabled="muting" @click="muteAll">
                خاموش کردن همه
            </button>
        </header>

        <p class="muted">
            این تنظیمات فقط برای حساب کاربری شماست و روی همکارانتان اثری ندارد.
        </p>

        <table class="table table--dense">
            <thead>
                <tr>
                    <th>دسته</th>
                    <th>درون‌برنامه</th>
                    <th>پوش</th>
                    <th>پیامک</th>
                    <th>ایمیل</th>
                    <th>ساعات سکوت</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="preferences.length === 0">
                    <td colspan="6" class="table__empty">در حال بارگذاری…</td>
                </tr>
                <tr v-for="row in preferences" v-else :key="row.category">
                    <td>{{ row.category_display || enumLabel('category', row.category) }}</td>
                    <td v-for="channel in CHANNELS" :key="channel">
                        <input
                            type="checkbox"
                            :checked="row[channel]"
                            :disabled="saving === row.category"
                            @change="setChannel(row, channel, $event.target.checked)"
                        >
                    </td>
                    <td>
                        <span v-if="row.has_quiet_hours" class="num" dir="ltr">
                            {{ row.quiet_hours_from }}–{{ row.quiet_hours_to }}
                        </span>
                        <span v-else class="muted">—</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="panel-card__section">
            <h3>ساعات سکوت</h3>
            <p class="muted">
                در این بازه اعلان‌های غیرفوری نگه داشته می‌شوند. هر دو مقدار باید با هم وارد شوند.
            </p>

            <label class="field">
                <span class="field__label">دسته</span>
                <select v-model="quiet.category" class="input">
                    <option value="">همه دسته‌ها</option>
                    <option v-for="row in preferences" :key="row.category" :value="row.category">
                        {{ row.category_display || row.category }}
                    </option>
                </select>
            </label>

            <label class="field">
                <span class="field__label">از ساعت</span>
                <input v-model="quiet.from" class="input num" dir="ltr" type="time">
            </label>

            <label class="field">
                <span class="field__label">تا ساعت</span>
                <input v-model="quiet.to" class="input num" dir="ltr" type="time">
            </label>

            <p v-if="error" class="order-form__error">{{ error }}</p>

            <button
                class="btn btn--primary btn--sm"
                type="button"
                :disabled="quiet.from === '' || quiet.to === ''"
                @click="saveQuietHours"
            >ذخیره ساعات سکوت</button>
        </div>
    </section>
</template>

<script setup>
/**
 * Notification preferences — `GET|PUT /notifications/preferences`, §15.3.
 *
 * USER-SCOPED, NOT ORGANISATION-SCOPED, and the screen says so out loud. The
 * controller is explicit that a preference row belongs to one person and that
 * «an OWNER rewriting a colleague's quiet hours» is deliberately impossible; a
 * member who expected this to be an organisation setting would otherwise assume
 * their team is covered.
 *
 * PARTIAL UPDATES. UpdatePreferencesRequest keeps any flag the client omits, so
 * one checkbox sends one field. Echoing all four back would race a colleague's
 * — or a second tab's — change and silently undo it.
 *
 * The response is re-read from the server rather than assumed: the stored
 * answer includes the §15.3 defaults for anything never explicitly set, which
 * is not what an optimistic local toggle would show.
 */
import { onMounted, ref } from 'vue';

import { enumLabel, loadEnums } from '../../Stores/enums.js';
import { notify, useApi } from '../../Stores/panel.js';

const api = useApi();

const CHANNELS = ['in_app', 'push', 'sms', 'email'];

const preferences = ref([]);
const saving = ref(null);
const muting = ref(false);
const error = ref('');
const quiet = ref({ category: '', from: '', to: '' });

async function reload() {
    try {
        const { data } = await api.get('/notifications/preferences');
        preferences.value = Array.isArray(data) ? data : [];
    } catch (err) {
        notify(err.message || 'دریافت تنظیمات اعلان ناموفق بود.', 'error');
    }
}

onMounted(() => {
    void loadEnums();
    void reload();
});

/** Merge the rows the server returned back into the table, by category. */
function merge(rows) {
    const returned = Array.isArray(rows) ? rows : [];

    preferences.value = preferences.value.map((row) => {
        const updated = returned.find((candidate) => candidate.category === row.category);
        return updated || row;
    });

    // A "mute everything" call returns all five; if the table was empty it is
    // simply replaced.
    if (preferences.value.length === 0) {
        preferences.value = returned;
    }
}

async function setChannel(row, channel, enabled) {
    saving.value = row.category;
    error.value = '';

    try {
        const { data } = await api.put('/notifications/preferences', {
            category: row.category,
            [channel]: enabled,
        });
        merge(data);
    } catch (err) {
        error.value = err.message || 'ذخیره تنظیمات ناموفق بود.';
        // Re-read so the checkbox reflects what is actually stored rather than
        // the state the click left it in.
        void reload();
    } finally {
        saving.value = null;
    }
}

async function muteAll() {
    muting.value = true;
    error.value = '';

    try {
        // No `category` — UpdatePreferencesRequest applies it to all five.
        const { data } = await api.put('/notifications/preferences', {
            in_app: false,
            push: false,
            sms: false,
            email: false,
        });
        merge(data);
        notify('همه اعلان‌ها خاموش شد.', 'success');
    } catch (err) {
        error.value = err.message || 'ذخیره تنظیمات ناموفق بود.';
    } finally {
        muting.value = false;
    }
}

async function saveQuietHours() {
    error.value = '';

    const body = {
        quiet_hours_from: quiet.value.from,
        quiet_hours_to: quiet.value.to,
    };

    if (quiet.value.category !== '') {
        body.category = quiet.value.category;
    }

    try {
        const { data } = await api.put('/notifications/preferences', body);
        merge(data);
        notify('ساعات سکوت ذخیره شد.', 'success');
    } catch (err) {
        error.value = err.message || 'ذخیره ساعات سکوت ناموفق بود.';
    }
}
</script>
