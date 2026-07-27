<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>گزارش‌ها</h1>
        </header>

        <nav class="report-tabs">
            <button
                v-for="report in REPORTS"
                :key="report.key"
                class="report-tabs__btn"
                :class="{ 'is-active': report.key === active }"
                type="button"
                @click="active = report.key"
            >{{ report.label }}</button>
        </nav>

        <DataTable
            :key="active"
            :endpoint="current.endpoint"
            :columns="current.columns"
            :filters="dateFilters"
            :export-path="active"
            :search-placeholder="`جستجو در ${current.label}…`"
            empty-text="داده‌ای برای این بازه وجود ندارد."
            show-totals
        />
    </div>
</template>

<script setup>
/**
 * Reports — §2.13's read-only endpoints behind one table.
 *
 * Export goes through `POST /reports/export`, which is asynchronous: the button
 * queues a job and the member is notified when the file is ready. A synchronous
 * download of a year of ledger rows would time out.
 */
import { computed, ref } from 'vue';

import DataTable from '../../Components/Data/DataTable.vue';

const REPORTS = [
    {
        key: 'gold-flow',
        label: 'گردش طلا',
        endpoint: '/reports/gold-flow',
        columns: [
            { key: 'period', label: 'دوره' },
            { key: 'opening_mg', label: 'افتتاحیه', type: 'weight' },
            { key: 'in_mg', label: 'ورودی', type: 'weight', total: true },
            { key: 'out_mg', label: 'خروجی', type: 'weight', total: true },
            { key: 'closing_mg', label: 'پایانی', type: 'weight' },
        ],
    },
    {
        key: 'rial-flow',
        label: 'گردش ریال',
        endpoint: '/reports/rial-flow',
        columns: [
            { key: 'period', label: 'دوره' },
            { key: 'opening_rial', label: 'افتتاحیه', type: 'money' },
            { key: 'in_rial', label: 'ورودی', type: 'money', total: true },
            { key: 'out_rial', label: 'خروجی', type: 'money', total: true },
            { key: 'closing_rial', label: 'پایانی', type: 'money' },
        ],
    },
    {
        key: 'pnl',
        label: 'سود و زیان',
        endpoint: '/reports/pnl',
        columns: [
            { key: 'period', label: 'دوره' },
            { key: 'realized_rial', label: 'محقق‌شده', type: 'money', total: true },
            { key: 'unrealized_rial', label: 'محقق‌نشده', type: 'money', total: true },
            { key: 'fees_rial', label: 'کارمزد', type: 'money', total: true },
            { key: 'net_rial', label: 'خالص', type: 'money', total: true },
        ],
    },
    {
        key: 'daily-profit',
        label: 'سود روزانه',
        endpoint: '/reports/daily-profit',
        columns: [
            { key: 'date', label: 'تاریخ', type: 'date', withTime: false, sortable: true },
            { key: 'volume_mg', label: 'حجم', type: 'weight', total: true },
            { key: 'profit_rial', label: 'سود', type: 'money', total: true },
        ],
    },
    {
        key: 'inventory',
        label: 'موجودی شمش',
        endpoint: '/reports/inventory',
        columns: [
            { key: 'lot_code', label: 'کد شمش' },
            { key: 'gross_weight_mg', label: 'ناخالص', type: 'weight', total: true },
            { key: 'fine_weight_mg', label: 'خالص', type: 'weight', total: true },
            { key: 'status', label: 'وضعیت', type: 'status' },
        ],
    },
    {
        key: 'fees',
        label: 'کارمزدها',
        endpoint: '/reports/fees',
        columns: [
            { key: 'period', label: 'دوره' },
            { key: 'trade_count', label: 'معاملات', type: 'number', total: true },
            { key: 'fee_rial', label: 'کارمزد', type: 'money', total: true },
            { key: 'tax_rial', label: 'مالیات', type: 'money', total: true },
        ],
    },
    {
        key: 'trial-balance',
        label: 'تراز آزمایشی',
        endpoint: '/reports/trial-balance',
        columns: [
            { key: 'account_code', label: 'کد حساب' },
            { key: 'account_name', label: 'نام حساب' },
            { key: 'debit_rial', label: 'بدهکار', type: 'money', total: true },
            { key: 'credit_rial', label: 'بستانکار', type: 'money', total: true },
        ],
    },
];

const active = ref('gold-flow');

const current = computed(() => REPORTS.find((r) => r.key === active.value) || REPORTS[0]);

const dateFilters = [
    { key: 'from', label: 'از', type: 'date' },
    { key: 'to', label: 'تا', type: 'date' },
];
</script>
