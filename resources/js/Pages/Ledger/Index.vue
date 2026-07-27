<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>{{ asset === 'gold' ? 'دفتر کل طلا' : 'دفتر کل ریال' }}</h1>

            <div class="segmented">
                <button
                    class="segmented__btn"
                    :class="{ 'is-active': asset === 'gold' }"
                    type="button"
                    @click="asset = 'gold'"
                >طلا</button>
                <button
                    class="segmented__btn"
                    :class="{ 'is-active': asset === 'rial' }"
                    type="button"
                    @click="asset = 'rial'"
                >ریال</button>
            </div>

            <label class="field field--inline">
                <span class="field__label">از</span>
                <input v-model="from" class="input input--sm" type="date">
            </label>
            <label class="field field--inline">
                <span class="field__label">تا</span>
                <input v-model="to" class="input input--sm" type="date">
            </label>
        </header>

        <DataTable
            ref="table"
            :key="asset"
            :endpoint="`/ledger/${asset}`"
            :columns="columns"
            :base-query="query"
            :export-path="`ledger-${asset}`"
            default-sort="-id"
            search-placeholder="جستجو در شرح…"
            empty-text="ثبتی در این بازه وجود ندارد."
            show-totals
            @loaded="onLoaded"
        >
            <template #cell:description="{ row }">
                <span class="ledger__description">
                    {{ row.description || entryTypeLabel(row.entry_type) }}
                    <a
                        v-if="documentLink(row)"
                        class="ledger__link"
                        :href="documentLink(row)"
                        :title="`سند مبنا: ${row.reference_type} #${row.reference_id}`"
                        @click.prevent="openDocument(row)"
                    >🔗</a>
                </span>
            </template>

            <template #cell:debit="{ row }">
                <span class="num" dir="ltr">{{ debitOf(row) }}</span>
            </template>

            <template #cell:credit="{ row }">
                <span class="num" dir="ltr">{{ creditOf(row) }}</span>
            </template>
        </DataTable>

        <section class="ledger__closing">
            <dl>
                <div>
                    <dt>مانده پایانی</dt>
                    <dd class="num" dir="ltr">{{ formatAmount(closingBalance) }}</dd>
                </div>
                <div v-if="balances">
                    <dt>├── در دسترس</dt>
                    <dd class="num" dir="ltr">{{ formatAmount(available) }}</dd>
                </div>
                <div v-if="balances">
                    <dt>├── رزروشده</dt>
                    <dd class="num" dir="ltr">{{ formatAmount(reserved) }}</dd>
                </div>
                <div v-if="balances">
                    <dt>└── در تسویه</dt>
                    <dd class="num" dir="ltr">{{ formatAmount(inSettlement) }}</dd>
                </div>
            </dl>
        </section>

        <section class="reconciliation" :class="`reconciliation--${reconciliation.state}`">
            <span class="reconciliation__icon">{{ reconciliation.icon }}</span>
            <span>{{ reconciliation.message }}</span>
        </section>
    </div>
</template>

<script setup>
/**
 * The running statement — doc §1.5.
 *
 * Two things distinguish this from a generic table.
 *
 * THE LINK COLUMN. Every row carries `reference_type` / `reference_id`, and the
 * 🔗 resolves it to the document that caused the movement — the trade, the
 * settlement, the assay certificate. A ledger line a member cannot trace to a
 * source document is a line they will dispute.
 *
 * THE RECONCILIATION BANNER. The footer re-derives the closing balance by
 * walking the rows and compares it against the balance the API reports
 * independently from `/balances`. Agreement is the normal case and says so;
 * disagreement is stated plainly with both figures rather than hidden, because
 * a silent mismatch is precisely the condition the banner exists to surface.
 * The comparison is exact integer arithmetic on BigInt — a float comparison
 * here would report false breaks on large balances.
 */
import { computed, ref, watch } from 'vue';

import DataTable from '../../Components/Data/DataTable.vue';
import { grams, rial, signedGrams, signedRial } from '../../lib/format.js';
import { useApi } from '../../Stores/panel.js';

const api = useApi();

const asset = ref('gold');
const from = ref('');
const to = ref('');
const table = ref(null);
const rows = ref([]);
const balances = ref(null);

const isGold = computed(() => asset.value === 'gold');

const query = computed(() => ({
    filter: {
        from: from.value || null,
        to: to.value || null,
    },
}));

const columns = computed(() => [
    { key: 'created_at', label: 'تاریخ', type: 'date', sortable: true },
    { key: 'description', label: 'شرح' },
    { key: 'debit', label: 'بدهکار', align: 'end', type: 'raw' },
    { key: 'credit', label: 'بستانکار', align: 'end', type: 'raw' },
    {
        key: 'balance_after',
        label: 'مانده',
        align: 'end',
        type: isGold.value ? 'weight' : 'money',
    },
]);

const ENTRY_TYPE_LABELS = {
    OPENING_BALANCE: 'مانده افتتاحیه',
    TRADE_BUY: 'خرید',
    TRADE_SELL: 'فروش',
    ORDER_RESERVE: 'رزرو سفارش',
    ORDER_RELEASE: 'آزادسازی رزرو',
    SETTLEMENT_IN: 'دریافت تسویه',
    SETTLEMENT_OUT: 'پرداخت تسویه',
    FEE: 'کارمزد',
    TAX: 'مالیات',
    ASSAY_ADJUSTMENT: 'تعدیل ری‌گیری',
    VAULT_DEPOSIT: 'سپرده خزانه',
    VAULT_WITHDRAWAL: 'تحویل فیزیکی',
    ROUNDING_DIFFERENCE: 'اختلاف گِردکردن',
};

function entryTypeLabel(type) {
    return ENTRY_TYPE_LABELS[type] || type || '—';
}

/** Signed amount: a DEBIT reduces the member's balance. */
function signed(row) {
    const amount = BigInt(row.amount ?? 0);
    return String(row.direction).toUpperCase() === 'DEBIT' ? -amount : amount;
}

function debitOf(row) {
    const value = signed(row);
    if (value >= 0n) {
        return '—';
    }
    return isGold.value ? signedGrams(value) : signedRial(value);
}

function creditOf(row) {
    const value = signed(row);
    if (value <= 0n) {
        return '—';
    }
    return isGold.value ? signedGrams(value) : signedRial(value);
}

function formatAmount(value) {
    if (value === null) {
        return '—';
    }
    return isGold.value ? grams(value) : rial(value);
}

/** Map a ledger reference onto the panel screen that shows that document. */
const REFERENCE_ROUTES = {
    TRADE: (id) => `/app/trades?focus=${id}`,
    ORDER: (id) => `/app/orders?focus=${id}`,
    SETTLEMENT: (id) => `/app/settlements?focus=${id}`,
    LOT: (id) => `/app/lots?focus=${id}`,
    ASSAY: (id) => `/app/lots?assay=${id}`,
    VAULT_OPERATION: (id) => `/app/lots?operation=${id}`,
};

function documentLink(row) {
    const builder = REFERENCE_ROUTES[String(row.reference_type).toUpperCase()];
    return builder && row.reference_id ? builder(row.reference_id) : null;
}

function openDocument(row) {
    const link = documentLink(row);
    if (link) {
        window.history.pushState({}, '', link);
        window.dispatchEvent(new PopStateEvent('popstate'));
    }
}

function onLoaded(payload) {
    rows.value = payload.rows;
}

const closingBalance = computed(() => {
    if (rows.value.length === 0) {
        return null;
    }
    // Rows arrive newest-first; the closing balance is the newest row's
    // `balance_after`, which the ledger wrote at insert time.
    return BigInt(rows.value[0].balance_after ?? 0);
});

/** Independently re-derived: opening balance plus every movement on screen. */
const derivedBalance = computed(() => {
    if (rows.value.length === 0) {
        return null;
    }
    const oldest = rows.value[rows.value.length - 1];
    let running = BigInt(oldest.balance_after ?? 0) - signed(oldest);

    for (let i = rows.value.length - 1; i >= 0; i--) {
        running += signed(rows.value[i]);
    }

    return running;
});

const available = computed(() => reported('available'));
const reserved = computed(() => reported('reserved'));
const inSettlement = computed(() => reported('in_settlement'));

function reported(kind) {
    if (!balances.value) {
        return null;
    }
    const bucket = isGold.value ? balances.value.gold : balances.value.rial;
    if (!bucket) {
        return null;
    }
    const key = isGold.value ? `${kind}_mg` : kind;
    return bucket[key] ?? null;
}

const reportedTotal = computed(() => {
    if (!balances.value) {
        return null;
    }
    return isGold.value
        ? BigInt(balances.value.gold.total_mg ?? 0)
        : BigInt(balances.value.rial.net ?? 0);
});

const reconciliation = computed(() => {
    if (rows.value.length === 0) {
        return { state: 'idle', icon: 'ℹ️', message: 'ردیفی برای تطبیق وجود ندارد.' };
    }

    if (derivedBalance.value !== closingBalance.value) {
        return {
            state: 'break',
            icon: '⛔',
            message: `تطبیق ناموفق: مانده محاسبه‌شده ${formatAmount(derivedBalance.value)} با مانده ثبت‌شده `
                + `${formatAmount(closingBalance.value)} اختلاف دارد. لطفاً با پشتیبانی تماس بگیرید.`,
        };
    }

    if (reportedTotal.value === null) {
        return {
            state: 'partial',
            icon: '✅',
            message: 'تطبیق: مانده محاسبه‌شده با مانده ثبت‌شده در دفتر مطابق است.',
        };
    }

    // The /balances figure covers the whole account; the statement covers a
    // date window. They tie out only on an unfiltered view, so say which case
    // this is rather than reporting a false break.
    const windowed = Boolean(from.value || to.value) || Boolean(table.value && rows.value.length >= 50);

    if (!windowed && reportedTotal.value !== closingBalance.value) {
        return {
            state: 'break',
            icon: '⛔',
            message: `تطبیق ناموفق: مانده دفتر ${formatAmount(closingBalance.value)} با موجودی گزارش‌شده `
                + `${formatAmount(reportedTotal.value)} اختلاف دارد.`,
        };
    }

    return {
        state: 'ok',
        icon: '✅',
        message: windowed
            ? 'تطبیق: مانده محاسبه‌شده با مانده ثبت‌شده مطابق است (بازه محدود؛ مقایسه با موجودی کل انجام نشد).'
            : 'تطبیق: مانده محاسبه‌شده با مانده ثبت‌شده مطابق است.',
    };
});

async function loadBalances() {
    try {
        const { data } = await api.get('/balances');
        balances.value = data;
    } catch {
        balances.value = null;
    }
}

watch(asset, () => {
    rows.value = [];
});

void loadBalances();
</script>
