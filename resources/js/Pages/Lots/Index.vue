<template>
    <div class="page" dir="rtl">
        <header class="page__header"><h1>شمش‌ها</h1></header>

        <DataTable
            endpoint="/lots"
            :columns="columns"
            :filters="filters"
            default-sort="-id"
            export-path="inventory"
            empty-text="شمشی به نام شما ثبت نشده است."
            show-totals
        >
            <template #cell:purity_x10="{ row }">
                <span class="num" dir="ltr">{{ purity(row.purity_x10) }}</span>
            </template>
        </DataTable>
    </div>
</template>

<script setup>
import DataTable from '../../Components/Data/DataTable.vue';
import { purity } from '../../lib/format.js';

const columns = [
    { key: 'lot_code', label: 'کد شمش' },
    { key: 'gross_weight_mg', label: 'وزن ناخالص', type: 'weight', total: true, sortable: true },
    { key: 'purity_x10', label: 'عیار', align: 'end' },
    { key: 'fine_weight_mg', label: 'وزن خالص', type: 'weight', total: true, sortable: true },
    { key: 'purity_source', label: 'منبع عیار' },
    { key: 'status', label: 'وضعیت', type: 'status' },
    { key: 'custodian_type', label: 'نگهدارنده' },
    { key: 'serial_number', label: 'سریال' },
];

const filters = [
    {
        key: 'status',
        label: 'وضعیت',
        options: [
            { value: 'AVAILABLE', label: 'در دسترس' },
            { value: 'RESERVED', label: 'رزرو شده' },
            { value: 'ON_HOLD', label: 'متوقف' },
        ],
    },
];
</script>
