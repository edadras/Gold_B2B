<template>
    <section class="book" :class="{ 'is-stale': stale }" dir="rtl">
        <header class="book__header">
            <h2>عمق بازار</h2>
            <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
        </header>

        <div class="book__side book__side--ask">
            <div class="book__label">فروش</div>
            <button
                v-for="level in asksDisplayed"
                :key="`a${level.price_rial}`"
                class="book__row book__row--ask"
                type="button"
                :title="`کلیک: قیمت ${level.price_rial} در فرم سفارش`"
                @click="$emit('pick', { side: 'BUY', price: level.price_rial, quantityMg: level.quantity_mg })"
            >
                <span class="book__price num" dir="ltr">{{ rial(level.price_rial) }}</span>
                <span class="book__qty num" dir="ltr">{{ grams(level.quantity_mg) }}</span>
                <span class="book__bar" :style="barStyle(level)" />
                <span class="book__count num" dir="ltr">{{ level.order_count }}</span>
            </button>
        </div>

        <div class="book__spread">
            <span class="muted">اسپرد</span>
            <span class="num" dir="ltr">{{ rial(depth.spread_rial) }}</span>
            <span class="muted">میانه</span>
            <span class="num" dir="ltr">{{ rial(depth.mid_price_rial) }}</span>
        </div>

        <div class="book__side book__side--bid">
            <button
                v-for="level in bidsDisplayed"
                :key="`b${level.price_rial}`"
                class="book__row book__row--bid"
                type="button"
                :title="`کلیک: قیمت ${level.price_rial} در فرم سفارش`"
                @click="$emit('pick', { side: 'SELL', price: level.price_rial, quantityMg: level.quantity_mg })"
            >
                <span class="book__price num" dir="ltr">{{ rial(level.price_rial) }}</span>
                <span class="book__qty num" dir="ltr">{{ grams(level.quantity_mg) }}</span>
                <span class="book__bar" :style="barStyle(level)" />
                <span class="book__count num" dir="ltr">{{ level.order_count }}</span>
            </button>
            <div class="book__label">خرید</div>
        </div>

        <p v-if="isEmpty" class="book__empty">سفارشی در دفتر نیست.</p>
    </section>
</template>

<script setup>
/**
 * The depth ladder — doc §1.3.
 *
 * CLICKING A ROW FILLS THE ORDER FORM AT THAT PRICE, and on the opposite side:
 * clicking an ask means "I want to buy at this price", which is the only
 * reading that matches what a trader is doing when they click an offer. The
 * emitted payload carries the level's aggregate quantity too, so the form can
 * offer to take the whole level.
 *
 * The ladder never shows who placed anything — the API's depth payload has no
 * owner field at all (see MarketDepthResource), so there is nothing here to
 * leak even by accident.
 *
 * Asks are rendered best-last (descending price) so the spread sits in the
 * middle of the component, matching the ASCII layout in the doc.
 */
import { computed } from 'vue';

import StaleBadge from '../Common/StaleBadge.vue';
import { grams, rial } from '../../lib/format.js';

const props = defineProps({
    depth: { type: Object, default: () => ({ bids: [], asks: [] }) },
    ageMs: { type: Number, default: null },
    staleAfterMs: { type: Number, default: 30000 },
    levels: { type: Number, default: 6 },
});

defineEmits(['pick']);

const stale = computed(() => props.ageMs === null || props.ageMs > props.staleAfterMs);

const bids = computed(() => props.depth.bids || []);
const asks = computed(() => props.depth.asks || []);

const bidsDisplayed = computed(() => bids.value.slice(0, props.levels));
// Reversed so the best ask sits adjacent to the spread row.
const asksDisplayed = computed(() => asks.value.slice(0, props.levels).slice().reverse());

const isEmpty = computed(() => bids.value.length === 0 && asks.value.length === 0);

const maxQuantity = computed(() => {
    const quantities = [...bids.value, ...asks.value].map((l) => Number(l.quantity_mg) || 0);
    return quantities.length ? Math.max(...quantities) : 0;
});

/**
 * Bar width is a presentation ratio, not a financial figure, so a float is
 * fine here — and it is clamped so a single huge level cannot overflow the row.
 */
function barStyle(level) {
    if (maxQuantity.value === 0) {
        return { width: '0%' };
    }
    const ratio = Math.min(1, (Number(level.quantity_mg) || 0) / maxQuantity.value);
    return { width: `${(ratio * 100).toFixed(1)}%` };
}
</script>
