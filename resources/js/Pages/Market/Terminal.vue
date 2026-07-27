<template>
    <div class="terminal" dir="rtl">
        <!-- Right rail (RTL: the first column) — instruments, balances, alerts -->
        <aside class="terminal__rail">
            <InstrumentList
                :instruments="market.instruments"
                :selected="market.selected"
                @select="select"
            />

            <BalanceRail
                :balances="market.balances"
                :age-ms="ages.balances"
                :stale-after-ms="staleAfter"
            />

            <section v-if="pendingSettlements > 0" class="rail-section rail-section--alert">
                <h2 class="rail-section__title">⚠️ اقدام لازم</h2>
                <a class="rail-alert" href="/app/settlements" @click.prevent="$emit('navigate', 'settlements')">
                    {{ pendingSettlements }} تسویه در انتظار شما
                </a>
            </section>
        </aside>

        <!-- Centre column — price, chart, depth, open orders -->
        <section class="terminal__centre">
            <PriceTicker
                :code="market.selected"
                :quote="market.quote"
                :age-ms="ages.quote"
                :stale-after-ms="staleAfter"
            />

            <PriceChart :candles="market.candles" :active="chartRange" @range="chartRange = $event" />

            <OrderBook
                :depth="market.depth"
                :age-ms="ages.depth"
                :stale-after-ms="staleAfter"
                @pick="onPick"
            />

            <OpenOrders
                :orders="market.openOrders"
                @cancel="cancelOne"
                @cancel-all="confirmCancelAll = true"
            />
        </section>

        <!-- Left column — the ticket and the tape -->
        <aside class="terminal__ticket">
            <OrderForm
                ref="orderForm"
                :instrument="instrument"
                :fee-rate-x100k="feeRate"
                :reference-price-rial="referencePrice"
                @placed="onPlaced"
            />

            <TradeTape
                :trades="market.tape"
                :age-ms="ages.tape"
                :stale-after-ms="staleAfter"
            />
        </aside>

        <ConfirmDialog
            :open="confirmCancelAll"
            title="لغو همه سفارش‌ها"
            confirm-label="لغو همه"
            @confirm="cancelAll"
            @cancel="confirmCancelAll = false"
        >
            <p>
                {{ market.openOrders.length }} سفارش باز روی
                <span dir="ltr">{{ market.selected }}</span>
                لغو خواهد شد. این عملیات بازگشت‌پذیر نیست.
            </p>
        </ConfirmDialog>
    </div>
</template>

<script setup>
/**
 * The trading terminal — doc §1.3.
 *
 * Three columns, in RTL order: instruments and balances on the right, the
 * price/chart/ladder/orders stack in the middle, the ticket and tape on the
 * left. This component owns the keyboard layer and the feed lifecycle; the
 * panels below it are presentational and emit intent.
 *
 * KEYBOARD (§1.3). Every binding is here rather than scattered through the
 * children, so there is one place to see what a key does and one place where
 * two bindings could collide.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import BalanceRail from '../../Components/Market/BalanceRail.vue';
import ConfirmDialog from '../../Components/Common/ConfirmDialog.vue';
import InstrumentList from '../../Components/Market/InstrumentList.vue';
import OpenOrders from '../../Components/Market/OpenOrders.vue';
import OrderBook from '../../Components/Market/OrderBook.vue';
import OrderForm from '../../Components/Market/OrderForm.vue';
import PriceChart from '../../Components/Market/PriceChart.vue';
import PriceTicker from '../../Components/Market/PriceTicker.vue';
import TradeTape from '../../Components/Market/TradeTape.vue';
import { useKeyboard } from '../../Composables/useKeyboard.js';
import { uuid } from '../../lib/api.js';
import {
    ages,
    loadInstruments,
    market,
    refreshBalances,
    refreshOrders,
    resyncAll,
    startTerminalFeeds,
    stopTerminalFeeds,
} from '../../Stores/market.js';
import { notify, panel, useApi } from '../../Stores/panel.js';

defineEmits(['navigate', 'help']);

const api = useApi();

const orderForm = ref(null);
const confirmCancelAll = ref(false);
const chartRange = ref('1m');
const pendingSettlements = ref(0);
const feeRate = ref(150);

const staleAfter = computed(() => panel.realtime.stale_after_ms || 30000);
const instrument = computed(() => market.instruments.find((i) => i.code === market.selected) || null);

/** Where the ↑/↓ keys start from on an empty ticket: mid, else last traded. */
const referencePrice = computed(() => market.depth.mid_price_rial
    ?? (market.quote ? market.quote.last_price_rial : null)
    ?? null);

function select(code) {
    if (code === market.selected) {
        return;
    }
    stopTerminalFeeds();
    market.selected = code;
    startTerminalFeeds();
}

/**
 * Depth-ladder click. The ladder emits the side the trader would be taking,
 * which is the opposite of the side of the level they clicked.
 */
function onPick(pick) {
    if (orderForm.value) {
        orderForm.value.applyPick(pick);
    }
}

function onPlaced() {
    void refreshOrders();
    void refreshBalances();
}

async function cancelOne(order) {
    try {
        await api.post(`/orders/${order.id}/cancel`, null, { idempotencyKey: uuid() });
        notify(`سفارش ${order.order_code} لغو شد.`, 'success');
        await refreshOrders();
        await refreshBalances();
    } catch (error) {
        notify(error.message || 'لغو سفارش ناموفق بود.', 'error');
    }
}

async function cancelAll() {
    confirmCancelAll.value = false;
    try {
        await api.post('/orders/cancel-all', { instrument: market.selected }, { idempotencyKey: uuid() });
        notify('همه سفارش‌های باز لغو شدند.', 'success');
        await refreshOrders();
        await refreshBalances();
    } catch (error) {
        notify(error.message || 'لغو سفارش‌ها ناموفق بود.', 'error');
    }
}

useKeyboard({
    b: () => orderForm.value && orderForm.value.focusSide('BUY'),
    s: () => orderForm.value && orderForm.value.focusSide('SELL'),
    Escape: () => {
        if (confirmCancelAll.value) {
            confirmCancelAll.value = false;
            return;
        }
        if (orderForm.value) {
            orderForm.value.clear();
        }
    },
    Enter: () => orderForm.value && orderForm.value.requestSubmit(),
    // Ctrl+Enter — submit without the confirmation step, for traders who have
    // opted into it. Same validation, same idempotency key; only the dialog is
    // skipped.
    'ctrl+Enter': () => orderForm.value && orderForm.value.submit(),
    ArrowUp: () => orderForm.value && orderForm.value.stepPrice(1),
    ArrowDown: () => orderForm.value && orderForm.value.stepPrice(-1),
    'shift+ArrowUp': () => orderForm.value && orderForm.value.stepPrice(10),
    'shift+ArrowDown': () => orderForm.value && orderForm.value.stepPrice(-10),
    'ctrl+a': () => {
        if (market.openOrders.length > 0) {
            confirmCancelAll.value = true;
        }
    },
    digit: (event, index) => {
        const target = market.instruments[index - 1];
        if (target) {
            select(target.code);
        }
    },
    '/': () => {
        const search = document.querySelector('.input--search');
        if (search) {
            search.focus();
        }
    },
});

onMounted(async () => {
    await loadInstruments();
    startTerminalFeeds();

    // The fee rate drives the ticket's cost preview. It is a system setting,
    // not a constant — reading it beats hard-coding 0.15% and being wrong the
    // day it changes.
    try {
        const { data } = await api.get('/meta/settings');
        if (data && data.trading && data.trading.taker_fee_rate_x100k) {
            feeRate.value = Number(data.trading.taker_fee_rate_x100k);
        }
    } catch {
        // Keep the documented default; the server is authoritative at submit.
    }

    try {
        const { data } = await api.get('/settlements/pending', { limit: 50 });
        pendingSettlements.value = Array.isArray(data) ? data.length : 0;
    } catch {
        pendingSettlements.value = 0;
    }
});

/**
 * Doc §1.6's post-reconnect re-sync. Coming back from a dropped connection or
 * a backgrounded tab, every feed re-reads from REST rather than waiting for the
 * next interval — otherwise the first thing the trader sees is a ladder that is
 * up to two seconds stale with no indication, or thirty if the socket is what
 * came back.
 */
function resyncOnReconnect() {
    if (document.visibilityState === 'visible') {
        void resyncAll();
    }
}

onMounted(() => {
    window.addEventListener('online', resyncOnReconnect);
    document.addEventListener('visibilitychange', resyncOnReconnect);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', resyncOnReconnect);
    document.removeEventListener('visibilitychange', resyncOnReconnect);
    stopTerminalFeeds();
});

watch(() => market.selected, (code) => {
    if (code) {
        document.title = `${code} — ترمینال معاملاتی`;
    }
});
</script>
