<template>
    <div class="page" dir="rtl">
        <header class="page__header"><h1>سفارش‌ها</h1></header>

        <DataTable
            ref="table"
            endpoint="/orders"
            :columns="columns"
            :filters="filters"
            default-sort="-placed_at"
            export-path="orders"
            empty-text="سفارشی ثبت نشده است."
            show-totals
        >
            <template #cell:side="{ row }">
                <span :class="row.side === 'BUY' ? 'is-buy' : 'is-sell'">
                    {{ row.side === 'BUY' ? 'خرید' : 'فروش' }}
                </span>
            </template>

            <template #cell:actions="{ row }">
                <button
                    v-if="isCancellable(row)"
                    class="btn btn--sm btn--ghost"
                    type="button"
                    @click.stop="cancel(row)"
                >لغو</button>
            </template>
        </DataTable>
    </div>
</template>

<script setup>
import { ref } from 'vue';

import DataTable from '../../Components/Data/DataTable.vue';
import { notify, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const api = useApi();
const table = ref(null);

const columns = [
    { key: 'order_code', label: 'کد' },
    { key: 'instrument', label: 'ابزار' },
    { key: 'side', label: 'سمت' },
    { key: 'type', label: 'نوع' },
    { key: 'quantity_mg', label: 'مقدار', type: 'weight', total: true, sortable: true },
    { key: 'filled_mg', label: 'اجرا شده', type: 'weight', total: true },
    { key: 'remaining_mg', label: 'باقیمانده', type: 'weight', total: true },
    { key: 'price_rial', label: 'قیمت', type: 'money', sortable: true },
    { key: 'status', label: 'وضعیت', type: 'status' },
    { key: 'placed_at', label: 'زمان ثبت', type: 'date', sortable: true },
    { key: 'actions', label: '' },
];

const filters = [
    {
        key: 'status',
        label: 'وضعیت',
        options: [
            { value: 'OPEN', label: 'باز' },
            { value: 'PARTIALLY_FILLED', label: 'اجرای جزئی' },
            { value: 'FILLED', label: 'اجرا شده' },
            { value: 'CANCELLED', label: 'لغو شده' },
            { value: 'EXPIRED', label: 'منقضی' },
        ],
    },
    {
        key: 'side',
        label: 'سمت',
        options: [{ value: 'BUY', label: 'خرید' }, { value: 'SELL', label: 'فروش' }],
    },
];

function isCancellable(row) {
    return ['OPEN', 'PARTIALLY_FILLED'].includes(row.status);
}

async function cancel(row) {
    try {
        await api.post(`/orders/${row.id}/cancel`, null, { idempotencyKey: uuid() });
        notify(`سفارش ${row.order_code} لغو شد.`, 'success');
        if (table.value) {
            await table.value.reload();
        }
    } catch (error) {
        notify(error.message || 'لغو سفارش ناموفق بود.', 'error');
    }
}
</script>
