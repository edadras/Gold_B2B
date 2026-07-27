<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>صف تسویه</h1>
            <div class="segmented">
                <button
                    class="segmented__btn"
                    :class="{ 'is-active': scope === 'pending' }"
                    type="button"
                    @click="scope = 'pending'"
                >در انتظار اقدام من</button>
                <button
                    class="segmented__btn"
                    :class="{ 'is-active': scope === 'all' }"
                    type="button"
                    @click="scope = 'all'"
                >همه</button>
            </div>
        </header>

        <DataTable
            :key="scope"
            :endpoint="scope === 'pending' ? '/settlements/pending' : '/settlements'"
            :columns="columns"
            :filters="filters"
            default-sort="deadline_at"
            export-path="settlements"
            empty-text="تسویه‌ای در این وضعیت نیست."
            :row-class="rowClass"
            show-totals
            @row-click="open"
        >
            <template #cell:actions="{ row }">
                <div class="row-actions">
                    <button
                        v-if="row.awaiting_me && row.my_roles.includes('CASH_PAYER')"
                        class="btn btn--sm btn--primary"
                        type="button"
                        @click.stop="declarePayment(row)"
                    >اعلام پرداخت</button>
                    <button
                        v-if="row.awaiting_me && row.my_roles.includes('CASH_RECEIVER')"
                        class="btn btn--sm btn--primary"
                        type="button"
                        @click.stop="confirmPayment(row)"
                    >تأیید دریافت</button>
                    <button
                        v-if="row.awaiting_me && row.my_roles.includes('GOLD_DELIVERER')"
                        class="btn btn--sm btn--primary"
                        type="button"
                        @click.stop="confirmDelivery(row)"
                    >تأیید تحویل</button>
                </div>
            </template>
        </DataTable>

        <p class="page__note">
            عملیات نشان‌دار با ✍️ نیاز به تأیید تراکنش (کد یکبارمصرف) دارند و از این صفحه
            به فرم تأیید هدایت می‌شوند.
        </p>
    </div>
</template>

<script setup>
/**
 * The settlement queue — the member's to-do list.
 *
 * Sorted by deadline ascending and never by anything else on first load: what
 * a treasurer needs from this screen is "what falls due next", and a queue
 * sorted by id buries the overdue row.
 *
 * Actions are rendered per ROLE. A member can be the cash payer on one
 * settlement and the gold deliverer on another, and offering the wrong button
 * produces a SETTLEMENT_NOT_YOUR_TURN rejection that reads as a bug.
 */
import { computed, ref } from 'vue';

import DataTable from '../../Components/Data/DataTable.vue';
import { notify, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const api = useApi();
const scope = ref('pending');

const columns = computed(() => [
    { key: 'settlement_code', label: 'کد' },
    { key: 'settlement_type', label: 'نوع' },
    { key: 'status', label: 'وضعیت', type: 'status' },
    { key: 'fine_weight_mg', label: 'وزن خالص', type: 'weight', total: true, sortable: true },
    { key: 'amount_due_rial', label: 'مبلغ', type: 'money', total: true, sortable: true },
    { key: 'penalty_rial', label: 'جریمه', type: 'money', total: true },
    { key: 'deadline_at', label: 'مهلت', type: 'date', sortable: true },
    { key: 'actions', label: '' },
]);

const filters = [
    {
        key: 'status',
        label: 'وضعیت',
        options: [
            { value: 'AWAITING_PAYMENT', label: 'در انتظار پرداخت' },
            { value: 'AWAITING_DELIVERY', label: 'در انتظار تحویل' },
            { value: 'OVERDUE', label: 'معوق' },
            { value: 'SETTLED', label: 'تسویه شده' },
            { value: 'DISPUTED', label: 'در اختلاف' },
        ],
    },
];

function rowClass(row) {
    if (row.status === 'OVERDUE' || row.overdue_since) {
        return 'row--danger';
    }
    return row.awaiting_me ? 'row--attention' : null;
}

function open(row) {
    notify(`تسویه ${row.settlement_code} — مهلت ${row.deadline_at_jalali || row.deadline_at || '—'}`, 'info');
}

async function act(row, path, body, successText) {
    try {
        await api.post(`/settlements/${row.id}/${path}`, body, { idempotencyKey: uuid() });
        notify(successText, 'success');
    } catch (error) {
        if (error.code === 'AUTH_TRANSACTION_SIGN_REQUIRED') {
            notify('این عملیات نیاز به تأیید تراکنش دارد. لطفاً کد یکبارمصرف را وارد کنید.', 'warn');
            return;
        }
        notify(error.message || 'عملیات ناموفق بود.', 'error');
    }
}

function declarePayment(row) {
    const reference = window.prompt('شماره پیگیری پرداخت را وارد کنید:');
    if (!reference) {
        return;
    }
    void act(row, 'declare-payment', {
        payment_reference: reference,
        amount_rial: row.amount_due_rial,
        paid_at: new Date().toISOString(),
    }, 'اعلام پرداخت ثبت شد.');
}

function confirmPayment(row) {
    void act(row, 'confirm-payment', {}, 'دریافت وجه تأیید شد.');
}

function confirmDelivery(row) {
    void act(row, 'confirm-delivery', {}, 'تحویل طلا تأیید شد.');
}
</script>
