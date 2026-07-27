<template>
    <section class="tape" dir="rtl">
        <header class="tape__header">
            <h2>معاملات اخیر</h2>
            <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
        </header>
        <ol class="tape__list">
            <li v-for="(trade, index) in trades" :key="trade.id || index" class="tape__row">
                <span class="tape__time num" dir="ltr">{{ clock(trade.executed_at, { seconds: false }) }}</span>
                <span class="tape__price num" dir="ltr">{{ rial(trade.price_rial ?? trade.price_per_gram_rial) }}</span>
                <span class="tape__qty num" dir="ltr">{{ grams(trade.quantity_mg ?? trade.quantity_fine_mg) }}</span>
                <span class="tape__dir" :class="direction(trade) === 'up' ? 'is-up' : 'is-down'">
                    {{ direction(trade) === 'up' ? '▲' : '▼' }}
                </span>
            </li>
        </ol>
        <p v-if="trades.length === 0" class="muted">معامله‌ای ثبت نشده.</p>
    </section>
</template>

<script setup>
/**
 * The tape — doc §1.3's bottom-right panel.
 *
 * The up/down arrow is the tick direction against the PREVIOUS print, which is
 * the convention every trading screen uses; deriving it here rather than
 * trusting an `aggressor_side` field means the arrow is right even on an
 * endpoint that does not disclose one.
 */
import StaleBadge from '../Common/StaleBadge.vue';
import { grams, rial } from '../../lib/format.js';
import { useFormatting } from '../../Composables/useFormatting.js';

const props = defineProps({
    trades: { type: Array, default: () => [] },
    ageMs: { type: Number, default: null },
    staleAfterMs: { type: Number, default: 30000 },
});

const { clock } = useFormatting();

function priceOf(trade) {
    return Number(trade.price_rial ?? trade.price_per_gram_rial ?? 0);
}

function direction(trade) {
    const index = props.trades.indexOf(trade);
    const previous = props.trades[index + 1];
    if (!previous) {
        return 'up';
    }
    return priceOf(trade) >= priceOf(previous) ? 'up' : 'down';
}
</script>
