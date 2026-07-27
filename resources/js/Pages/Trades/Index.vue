<template>
    <div class="page" dir="rtl">
        <header class="page__header"><h1>معاملات</h1></header>

        <DataTable
            endpoint="/reports/trades"
            :columns="columns"
            :filters="filters"
            default-sort="-executed_at"
            export-path="trades"
            empty-text="معامله‌ای در این بازه ثبت نشده است."
            show-totals
        >
            <template #cell:my_side="{ row }">
                <span :class="row.my_side === 'BUY' ? 'is-buy' : 'is-sell'">
                    {{ row.my_side === 'BUY' ? 'خرید' : 'فروش' }}
                </span>
            </template>
        </DataTable>
    </div>
</template>

<script setup>
import DataTable from '../../Components/Data/DataTable.vue';

const columns = [
    { key: 'trade_code', label: 'کد' },
    { key: 'instrument', label: 'ابزار' },
    { key: 'my_side', label: 'سمت' },
    { key: 'quantity_fine_mg', label: 'وزن خالص', type: 'weight', total: true, sortable: true },
    { key: 'price_per_gram_rial', label: 'قیمت', type: 'money', sortable: true },
    { key: 'gross_amount_rial', label: 'ناخالص', type: 'money', total: true },
    { key: 'my_fee_rial', label: 'کارمزد', type: 'money', total: true },
    { key: 'my_net_rial', label: 'خالص', type: 'money', total: true },
    { key: 'settlement_type', label: 'تسویه' },
    { key: 'status', label: 'وضعیت', type: 'status' },
    { key: 'executed_at', label: 'زمان', type: 'date', sortable: true },
];

const filters = [
    {
        key: 'side',
        label: 'سمت',
        options: [{ value: 'BUY', label: 'خرید' }, { value: 'SELL', label: 'فروش' }],
    },
    { key: 'executed_from', label: 'از', type: 'date' },
    { key: 'executed_to', label: 'تا', type: 'date' },
];
</script>
