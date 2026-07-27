<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>معاملات توافقی</h1>

            <div class="segmented">
                <button
                    v-for="scope in scopes"
                    :key="scope.key"
                    class="segmented__btn"
                    :class="{ 'is-active': direction === scope.key }"
                    type="button"
                    @click="direction = scope.key"
                >{{ scope.label }}</button>
            </div>

            <button class="btn btn--primary btn--sm" type="button" @click="composing = ! composing">
                {{ composing ? 'بستن فرم' : 'پیشنهاد جدید' }}
            </button>
        </header>

        <OfferForm
            v-if="composing"
            :instruments="instruments"
            @created="onCreated"
            @close="composing = false"
        />

        <DataTable
            ref="table"
            :key="direction"
            endpoint="/otc-offers"
            :base-query="baseQuery"
            :columns="columns"
            :searchable="false"
            :age-ms="ageMs"
            :stale-after-ms="staleAfterMs"
            empty-text="پیشنهاد توافقی‌ای در این دسته نیست."
            :row-class="rowClass"
            @loaded="touch"
        >
            <template #cell:expires_at="{ row }">
                <Countdown :expires-at="row.expires_at" />
            </template>

            <template #cell:rounds="{ row }">
                <span class="num" dir="ltr">{{ row.round_count }} / {{ row.max_rounds }}</span>
            </template>

            <template #cell:actions="{ row }">
                <div class="row-actions">
                    <template v-if="row.awaiting_me && ! isExpired(row)">
                        <button class="btn btn--sm btn--primary" type="button" @click.stop="askAccept(row)">
                            پذیرش
                        </button>
                        <button
                            v-if="remainingRounds(row) > 0"
                            class="btn btn--sm btn--ghost"
                            type="button"
                            @click.stop="countering = row"
                        >پیشنهاد متقابل</button>
                        <button class="btn btn--sm btn--ghost" type="button" @click.stop="reject(row)">
                            رد
                        </button>
                    </template>
                    <button
                        v-else-if="! row.awaiting_me && isLive(row)"
                        class="btn btn--sm btn--ghost"
                        type="button"
                        @click.stop="cancel(row)"
                    >لغو پیشنهاد</button>
                    <span v-else class="muted">—</span>
                </div>
            </template>
        </DataTable>

        <CounterDialog
            :offer="countering"
            @countered="onChanged"
            @close="countering = null"
        />

        <ConfirmDialog
            :open="accepting !== null"
            title="پذیرش پیشنهاد توافقی"
            confirm-label="پذیرش و ایجاد معامله"
            @confirm="accept"
            @cancel="accepting = null"
        >
            <dl v-if="accepting" class="confirm-list">
                <div><dt>کد</dt><dd dir="ltr">{{ accepting.offer_code }}</dd></div>
                <div><dt>سمت شما</dt><dd>{{ accepting.my_side === 'BUY' ? 'خرید' : 'فروش' }}</dd></div>
                <div><dt>مقدار خالص</dt><dd><WeightCell :mg="accepting.quantity_mg" show-unit /></dd></div>
                <div><dt>قیمت</dt><dd><MoneyCell :rial="accepting.price_rial" /></dd></div>
                <div><dt>ارزش کل</dt><dd><MoneyCell :rial="acceptNotional" show-unit /></dd></div>
                <div><dt>مهلت</dt><dd><Countdown :expires-at="accepting.expires_at" /></dd></div>
            </dl>
            <p class="muted">پذیرش، معامله را ایجاد و تسویه را آغاز می‌کند و بازگشت‌پذیر نیست.</p>
        </ConfirmDialog>
    </div>
</template>

<script setup>
/**
 * OTC — the bilateral negotiation screen of §4.6.
 *
 * WHOSE TURN IT IS drives the whole screen. `OtcOfferResource` already computes
 * `awaiting_me`, so the row offers accept / counter / reject only to the side
 * that may act, and the standing proposer sees «لغو پیشنهاد» instead. Rendering
 * every button to everybody would produce an OFFER_NOT_YOUR_TURN rejection that
 * reads as a bug in the panel rather than as a rule of the market.
 *
 * EXPIRY IS ENFORCED IN THE UI TOO. An offer whose countdown has run out stops
 * offering an accept button before the server refuses it: §4.6 gives an offer a
 * hard life, and a member who presses accept on a dead offer has been misled by
 * the screen, not by the market.
 *
 * IDEMPOTENCY. `/otc-offers/{id}/accept` and `/counter` both carry the
 * `idempotency` middleware. Accepting mints a key WHEN THE CONFIRM DIALOG OPENS
 * and keeps it for every retry of that acceptance; `/reject` and `/cancel` are
 * naturally idempotent (the second call finds a closed offer) and the routes do
 * not carry the middleware, so they are sent without a key rather than with a
 * meaningless one.
 *
 * FRESHNESS. Offers expire on a clock, so the list refreshes itself and carries
 * its own age badge; a failed refresh keeps the rows and lets the age climb —
 * see `useFreshness`.
 */
import { computed, onMounted, ref } from 'vue';

import ConfirmDialog from '../../Components/Common/ConfirmDialog.vue';
import Countdown from '../../Components/Common/Countdown.vue';
import CounterDialog from '../../Components/Otc/CounterDialog.vue';
import DataTable from '../../Components/Data/DataTable.vue';
import MoneyCell from '../../Components/Data/MoneyCell.vue';
import OfferForm from '../../Components/Otc/OfferForm.vue';
import WeightCell from '../../Components/Data/WeightCell.vue';
import { loadEnums } from '../../Stores/enums.js';
import { notionalValue, remainingMs, roundsRemaining } from '../../lib/negotiation.js';
import { notify, useApi } from '../../Stores/panel.js';
import { useAutoRefresh, useClock, useFreshness } from '../../Composables/useFreshness.js';
import { uuid } from '../../lib/api.js';

const api = useApi();
const { ageMs, staleAfterMs, touch } = useFreshness();
// A ticking clock, so an offer's buttons disappear the second it expires
// rather than at the next refetch.
const now = useClock();

const table = ref(null);
const direction = ref('received');
const composing = ref(false);
const countering = ref(null);
const accepting = ref(null);
const acceptKey = ref(null);
const instruments = ref([]);

const scopes = [
    { key: 'received', label: 'دریافتی' },
    { key: 'sent', label: 'ارسالی' },
    { key: '', label: 'همه' },
];

const baseQuery = computed(() => (direction.value === '' ? {} : { direction: direction.value }));

/**
 * OtcOfferStatus's own labels. Kept here rather than pulled from `/meta/enums`
 * because `StatusBadge` needs a colour as well as a word, and COUNTERED — the
 * state that means "your move" — has no equivalent anywhere else in the panel.
 */
const STATUS_LABELS = {
    PENDING: 'در انتظار پاسخ',
    COUNTERED: 'پیشنهاد متقابل',
    ACCEPTED: 'پذیرفته شده',
    REJECTED: 'رد شده',
    CANCELLED: 'لغو شده',
    EXPIRED: 'منقضی',
};

const columns = [
    { key: 'offer_code', label: 'کد' },
    { key: 'instrument', label: 'ابزار' },
    { key: 'my_side', label: 'سمت شما', value: (row) => (row.my_side === 'BUY' ? 'خرید' : 'فروش') },
    { key: 'counterparty_of_viewer_id', label: 'طرف مقابل', type: 'number' },
    { key: 'quantity_mg', label: 'وزن خالص', type: 'weight', total: true },
    { key: 'price_rial', label: 'قیمت', type: 'money' },
    { key: 'status', label: 'وضعیت', type: 'status', statusMap: STATUS_LABELS },
    { key: 'rounds', label: 'دور' },
    { key: 'expires_at', label: 'مهلت' },
    { key: 'actions', label: '' },
];

const acceptNotional = computed(() => {
    if (accepting.value === null) {
        return null;
    }
    try {
        return notionalValue(accepting.value.quantity_mg, accepting.value.price_rial).toString();
    } catch {
        return null;
    }
});

const isExpired = (row) => remainingMs(row.expires_at, now.value) === 0;
/** Still negotiable: an open status AND time left on the clock. */
const isOpenStatus = (row) => ['PENDING', 'COUNTERED'].includes(row.status);
const isLive = (row) => isOpenStatus(row) && ! isExpired(row);
const remainingRounds = (row) => roundsRemaining(row);

function rowClass(row) {
    if (row.awaiting_me && isLive(row)) {
        return 'row--attention';
    }
    // Open on the server, dead on the clock: the sweeper has not closed it yet,
    // and the row must not look actionable in the meantime.
    return isOpenStatus(row) && isExpired(row) ? 'row--danger' : null;
}

function reload() {
    return table.value ? table.value.reload() : Promise.resolve();
}

useAutoRefresh(reload, { intervalMs: 15000 });

onMounted(async () => {
    void loadEnums();
    try {
        const { data } = await api.get('/instruments');
        instruments.value = Array.isArray(data) ? data : [];
    } catch {
        // The offer form falls back to an empty instrument list and refuses to
        // submit; the table itself does not need instruments.
        instruments.value = [];
    }
});

function askAccept(row) {
    accepting.value = row;
    // §1.10 — the key belongs to this acceptance, minted as the dialog opens.
    acceptKey.value = uuid();
}

async function accept() {
    const offer = accepting.value;

    if (offer === null) {
        return;
    }

    try {
        const { data, replayed } = await api.post(`/otc-offers/${offer.id}/accept`, {}, {
            idempotencyKey: acceptKey.value,
        });

        notify(
            replayed
                ? 'این پیشنهاد قبلاً پذیرفته شده بود؛ همان معامله بازگردانده شد.'
                : `معامله ${data && data.trade_code ? data.trade_code : ''} ایجاد شد.`,
            'success',
        );

        accepting.value = null;
        acceptKey.value = null;
        void reload();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            // Keep the dialog and the key open: retrying resolves this.
            notify('درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.', 'warn');
            return;
        }

        notify(error.message || 'پذیرش پیشنهاد ناموفق بود.', 'error');
        accepting.value = null;
        acceptKey.value = null;
        void reload();
    }
}

async function respond(row, path, successText) {
    const reason = window.prompt('دلیل (اختیاری):') ?? '';

    try {
        // Neither route carries the idempotency middleware — both are idempotent
        // by nature, since the second call finds a closed offer — so no key is
        // sent rather than one that the server would ignore.
        await api.post(`/otc-offers/${row.id}/${path}`, reason === '' ? {} : { reason });
        notify(successText, 'success');
    } catch (error) {
        notify(error.message || 'عملیات ناموفق بود.', 'error');
    } finally {
        void reload();
    }
}

const reject = (row) => respond(row, 'reject', 'پیشنهاد رد شد.');
const cancel = (row) => respond(row, 'cancel', 'پیشنهاد لغو شد.');

function onCreated() {
    composing.value = false;
    void reload();
}

function onChanged() {
    void reload();
}
</script>
