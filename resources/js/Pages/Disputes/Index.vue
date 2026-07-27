<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>اختلافات</h1>

            <div class="segmented">
                <button
                    v-for="option in scopes"
                    :key="option.key"
                    class="segmented__btn"
                    :class="{ 'is-active': scope === option.key }"
                    type="button"
                    @click="scope = option.key"
                >{{ option.label }}</button>
            </div>

            <button class="btn btn--primary btn--sm" type="button" @click="composing = ! composing">
                {{ composing ? 'بستن فرم' : 'ثبت اختلاف' }}
            </button>
        </header>

        <OpenForm v-if="composing" @opened="onOpened" @close="composing = false" />

        <DataTable
            ref="table"
            :key="scope"
            endpoint="/disputes"
            :columns="columns"
            :row-filter="rowFilter"
            :searchable="false"
            :age-ms="ageMs"
            :stale-after-ms="staleAfterMs"
            empty-text="پرونده اختلافی ندارید."
            :row-class="rowClass"
            @row-click="open"
            @loaded="onLoaded"
        >
            <template #cell:actions="{ row }">
                <button class="btn btn--sm btn--ghost" type="button" @click.stop="open(row)">پرونده</button>
            </template>
        </DataTable>

        <p v-if="hiddenCount > 0" class="page__note">
            <span class="num" dir="ltr">{{ hiddenCount }}</span>
            پرونده دیگر با این فیلتر نمایش داده نشده است.
        </p>

        <CaseDetail :case-id="selectedId" @changed="reload" @close="selectedId = null" />
    </div>
</template>

<script setup>
/**
 * Disputes — §13.
 *
 * `GET /disputes` returns both sides of the member's cases in one collection,
 * so the split between «شکایت‌های من» and «علیه من» is done here on `my_role`
 * rather than by a query parameter the endpoint does not accept. Doing it
 * client-side is honest as long as the whole list is present, which it is: the
 * endpoint returns the caller's cases without pagination.
 *
 * A case that is open AND awaiting this member's reply is the row that costs
 * money to ignore — §13 gives the respondent a hard reply deadline — so it is
 * highlighted rather than left to be found by reading the status column.
 */
import { computed, ref } from 'vue';

import CaseDetail from '../../Components/Disputes/CaseDetail.vue';
import DataTable from '../../Components/Data/DataTable.vue';
import OpenForm from '../../Components/Disputes/OpenForm.vue';
import { enumLabel, loadEnums } from '../../Stores/enums.js';
import { useAutoRefresh, useFreshness } from '../../Composables/useFreshness.js';

const { ageMs, staleAfterMs, touch } = useFreshness();

const table = ref(null);
const scope = ref('all');
const composing = ref(false);
const selectedId = ref(null);
const hiddenCount = ref(0);

const scopes = [
    { key: 'all', label: 'همه' },
    { key: 'mine', label: 'شکایت‌های من' },
    { key: 'against', label: 'علیه من' },
    { key: 'open', label: 'باز' },
];

const STATUS_LABELS = {
    OPENED: 'ثبت شده',
    AWAITING_REPLY: 'در انتظار پاسخ',
    ACCEPTED_BY_RESPONDENT: 'پذیرفته‌شده',
    NEGOTIATION: 'در مذاکره',
    UNDER_MEDIATION: 'در میانجی‌گری',
    AWAITING_EVIDENCE: 'در انتظار مدرک',
    AWAITING_REASSAY: 'در انتظار ری‌گیری',
    RESOLVED: 'رأی صادر شده',
    EXECUTED: 'اجرا شده',
    WITHDRAWN: 'پس گرفته شده',
};

const columns = computed(() => [
    { key: 'case_number', label: 'شماره پرونده' },
    {
        key: 'dispute_type',
        label: 'نوع',
        value: (row) => row.dispute_type_display || enumLabel('dispute_type', row.dispute_type),
    },
    { key: 'my_role', label: 'نقش شما', value: (row) => (row.my_role === 'CLAIMANT' ? 'شاکی' : 'طرف شکایت') },
    { key: 'claim_gold_mg', label: 'طلای مورد ادعا', type: 'weight', total: true },
    { key: 'claim_rial', label: 'مبلغ مورد ادعا', type: 'money', total: true },
    { key: 'status', label: 'وضعیت', type: 'status', statusMap: STATUS_LABELS },
    { key: 'reply_deadline_at', label: 'مهلت پاسخ', type: 'date' },
    { key: 'opened_at', label: 'ثبت', type: 'date' },
    { key: 'actions', label: '' },
]);

/**
 * A respondent on an open case owes an answer; a claimant does not. Colouring
 * both would make the colour mean "there is a dispute", which the row already
 * says by existing.
 */
function rowClass(row) {
    if (! row.is_open) {
        return null;
    }
    return row.my_role === 'RESPONDENT' ? 'row--attention' : null;
}

/**
 * `GET /disputes` accepts no filter parameters, so the tabs narrow what has
 * already arrived rather than sending `filter[…]` the server would ignore —
 * see DataTable's `rowFilter`.
 */
const rowFilter = computed(() => {
    const current = scope.value;

    return (row) => {
        if (current === 'mine') {
            return row.my_role === 'CLAIMANT';
        }
        if (current === 'against') {
            return row.my_role === 'RESPONDENT';
        }
        if (current === 'open') {
            return Boolean(row.is_open);
        }
        return true;
    };
});

function onLoaded({ received }) {
    touch();
    hiddenCount.value = Math.max(0, received.length - visibleCount(received));
    void loadEnums();
}

function visibleCount(received) {
    return received.filter(rowFilter.value).length;
}

function reload() {
    return table.value ? table.value.reload() : Promise.resolve();
}

useAutoRefresh(reload, { intervalMs: 30000 });

function open(row) {
    selectedId.value = row.id;
}

function onOpened(created) {
    composing.value = false;
    if (created && created.id) {
        selectedId.value = created.id;
    }
    void reload();
}
</script>
