<template>
    <div class="page" dir="rtl">
        <header class="page__header"><h1>طرف‌حساب‌ها</h1></header>

        <DataTable
            endpoint="/counterparties"
            :columns="columns"
            default-sort="-total_volume_rial"
            empty-text="هنوز با عضوی معامله نکرده‌اید."
            show-totals
            @row-click="showStatement"
        />

        <section v-if="statement" class="panel-card">
            <header class="panel-card__header">
                <h2>صورت‌حساب {{ statement.counterparty_name || statement.organization_id }}</h2>
                <button class="btn btn--ghost btn--sm" type="button" @click="statement = null">بستن</button>
            </header>
            <dl class="kv">
                <div><dt>مانده طلا</dt><dd class="num" dir="ltr">{{ grams(statement.net_gold_mg) }}</dd></div>
                <div><dt>مانده ریالی</dt><dd class="num" dir="ltr">{{ rial(statement.net_rial) }}</dd></div>
                <div><dt>تعداد معاملات</dt><dd class="num" dir="ltr">{{ statement.trade_count ?? '—' }}</dd></div>
            </dl>
        </section>
    </div>
</template>

<script setup>
import { ref } from 'vue';

import DataTable from '../../Components/Data/DataTable.vue';
import { grams, rial } from '../../lib/format.js';
import { notify, useApi } from '../../Stores/panel.js';

const api = useApi();
const statement = ref(null);

const columns = [
    { key: 'organization_id', label: 'شناسه', type: 'number' },
    { key: 'display_name', label: 'نام' },
    { key: 'relationship_status', label: 'وضعیت', type: 'status' },
    { key: 'trade_count', label: 'معاملات', type: 'number', total: true },
    { key: 'total_volume_mg', label: 'حجم', type: 'weight', total: true, sortable: true },
    { key: 'net_rial', label: 'مانده ریالی', type: 'money', total: true },
    { key: 'credit_limit_rial', label: 'سقف اعتبار', type: 'money' },
    { key: 'last_traded_at', label: 'آخرین معامله', type: 'date', sortable: true },
];

async function showStatement(row) {
    try {
        const { data } = await api.get(`/counterparties/${row.organization_id}/statement`);
        statement.value = { ...data, counterparty_name: row.display_name };
    } catch (error) {
        notify(error.message || 'دریافت صورت‌حساب ناموفق بود.', 'error');
    }
}
</script>
