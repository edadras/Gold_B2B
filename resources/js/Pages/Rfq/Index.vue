<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>درخواست قیمت</h1>

            <div class="segmented">
                <button
                    class="segmented__btn"
                    :class="{ 'is-active': scope === 'mine' }"
                    type="button"
                    @click="setScope('mine')"
                >درخواست‌های من</button>
                <button
                    class="segmented__btn"
                    :class="{ 'is-active': scope === 'inbox' }"
                    type="button"
                    @click="setScope('inbox')"
                >دریافتی</button>
            </div>

            <button class="btn btn--primary btn--sm" type="button" @click="composing = ! composing">
                {{ composing ? 'بستن فرم' : 'درخواست جدید' }}
            </button>
        </header>

        <RequestForm
            v-if="composing"
            :instruments="instruments"
            @created="onCreated"
            @close="composing = false"
        />

        <DataTable
            ref="table"
            :key="scope"
            :endpoint="scope === 'mine' ? '/rfqs' : '/rfqs/inbox'"
            :columns="columns"
            :searchable="false"
            :age-ms="ageMs"
            :stale-after-ms="staleAfterMs"
            :empty-text="scope === 'mine' ? 'درخواستی ثبت نکرده‌اید.' : 'درخواستی برای شما ارسال نشده است.'"
            :row-class="rowClass"
            @row-click="open"
            @loaded="touch"
        >
            <template #cell:expires_at="{ row }">
                <Countdown :expires-at="row.expires_at" />
            </template>

            <template #cell:actions="{ row }">
                <div class="row-actions">
                    <button class="btn btn--sm btn--ghost" type="button" @click.stop="open(row)">
                        پیشنهادها
                    </button>
                    <button
                        v-if="row.is_mine && isLive(row)"
                        class="btn btn--sm btn--ghost"
                        type="button"
                        @click.stop="cancel(row)"
                    >لغو</button>
                </div>
            </template>
        </DataTable>

        <QuotePanel
            :rfq="selected"
            @accepted="onChanged"
            @quoted="onChanged"
            @close="selected = null"
        />
    </div>
</template>

<script setup>
/**
 * RFQ — the request-for-quote screen of §4.7.
 *
 * TWO INBOXES, TWO PERMISSIONS. `/rfqs` is what this member asked (RFQ_CREATE)
 * and `/rfqs/inbox` is what this member was asked (RFQ_RESPOND); they are
 * separate endpoints on the server because they are separate rights, so they
 * are separate tabs here rather than one list with a column. A member holding
 * only one of the two permissions gets a 403 on the other tab and sees the
 * error rather than an empty table that looks like "no business today".
 *
 * The quotes panel is opened by clicking a row and owns its own feed; see
 * QuotePanel for why ranking is side-dependent.
 */
import { computed, onMounted, ref } from 'vue';

import Countdown from '../../Components/Common/Countdown.vue';
import DataTable from '../../Components/Data/DataTable.vue';
import QuotePanel from '../../Components/Rfq/QuotePanel.vue';
import RequestForm from '../../Components/Rfq/RequestForm.vue';
import { remainingMs } from '../../lib/negotiation.js';
import { notify, useApi } from '../../Stores/panel.js';
import { useAutoRefresh, useClock, useFreshness } from '../../Composables/useFreshness.js';

const api = useApi();
const { ageMs, staleAfterMs, touch } = useFreshness();
// Ticking, so «لغو» stops being offered on a request that has just expired.
const now = useClock();

const table = ref(null);
const scope = ref('mine');
const composing = ref(false);
const selected = ref(null);
const instruments = ref([]);

const STATUS_LABELS = {
    OPEN: 'باز',
    QUOTED: 'دارای پیشنهاد',
    PARTIALLY_ACCEPTED: 'پذیرش جزئی',
    ACCEPTED: 'پذیرفته شده',
    CANCELLED: 'لغو شده',
    EXPIRED: 'منقضی',
};

const columns = computed(() => [
    { key: 'rfq_code', label: 'کد' },
    { key: 'instrument', label: 'ابزار' },
    { key: 'side', label: 'سمت', value: (row) => (row.side === 'BUY' ? 'خرید' : 'فروش') },
    { key: 'quantity_mg', label: 'مقدار', type: 'weight', total: true },
    { key: 'remaining_mg', label: 'باقی‌مانده', type: 'weight', total: true },
    { key: 'min_purity_x10', label: 'حداقل عیار', type: 'number' },
    { key: 'visibility', label: 'دامنه', value: (row) => VISIBILITY[row.visibility] || row.visibility },
    { key: 'status', label: 'وضعیت', type: 'status', statusMap: STATUS_LABELS },
    { key: 'expires_at', label: 'مهلت' },
    { key: 'actions', label: '' },
]);

const VISIBILITY = {
    SELECTED: 'منتخب',
    ALL_QUALIFIED: 'عمومی',
    ANONYMOUS: 'ناشناس',
};

const isLive = (row) => ['OPEN', 'QUOTED', 'PARTIALLY_ACCEPTED'].includes(row.status)
    && remainingMs(row.expires_at, now.value) !== 0;

function rowClass(row) {
    if (! isLive(row)) {
        return null;
    }
    // An unanswered request in the inbox is the row that needs a decision.
    return scope.value === 'inbox' ? 'row--attention' : null;
}

function reload() {
    return table.value ? table.value.reload() : Promise.resolve();
}

useAutoRefresh(reload, { intervalMs: 15000 });

onMounted(async () => {
    try {
        const { data } = await api.get('/instruments');
        instruments.value = Array.isArray(data) ? data : [];
    } catch {
        instruments.value = [];
    }
});

function setScope(next) {
    scope.value = next;
    selected.value = null;
}

function open(row) {
    selected.value = row;
}

async function cancel(row) {
    try {
        // Not an idempotency route: the second call finds a cancelled RFQ.
        await api.post(`/rfqs/${row.id}/cancel`, {});
        notify('درخواست لغو شد.', 'success');
    } catch (error) {
        notify(error.message || 'لغو درخواست ناموفق بود.', 'error');
    } finally {
        if (selected.value && selected.value.id === row.id) {
            selected.value = null;
        }
        void reload();
    }
}

function onCreated() {
    composing.value = false;
    void reload();
}

function onChanged() {
    void reload();
}
</script>
