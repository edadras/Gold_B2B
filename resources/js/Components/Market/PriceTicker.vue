<template>
    <header class="ticker" :class="{ 'is-stale': stale }" dir="rtl">
        <div class="ticker__code" dir="ltr">{{ code || '—' }}</div>
        <div class="ticker__price num" dir="ltr">{{ rial(quote && quote.last_price_rial) }}</div>
        <div
            v-if="quote && quote.change_bps !== null && quote.change_bps !== undefined"
            class="ticker__change"
            :class="quote.change_bps >= 0 ? 'is-up' : 'is-down'"
            dir="ltr"
        >
            {{ quote.change_bps >= 0 ? '▲' : '▼' }} {{ bps(quote.change_bps, { sign: true }) }}
        </div>
        <div class="ticker__spacer" />
        <dl class="ticker__stats">
            <div><dt>بالاترین</dt><dd class="num" dir="ltr">{{ rial(quote && quote.day_high_rial) }}</dd></div>
            <div><dt>پایین‌ترین</dt><dd class="num" dir="ltr">{{ rial(quote && quote.day_low_rial) }}</dd></div>
            <div><dt>حجم</dt><dd class="num" dir="ltr">{{ grams(quote && quote.day_volume_mg) }}</dd></div>
        </dl>
        <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
    </header>
</template>

<script setup>
import { computed } from 'vue';

import StaleBadge from '../Common/StaleBadge.vue';
import { bps, grams, rial } from '../../lib/format.js';

const props = defineProps({
    code: { type: String, default: null },
    quote: { type: Object, default: null },
    ageMs: { type: Number, default: null },
    staleAfterMs: { type: Number, default: 30000 },
});

const stale = computed(() => props.ageMs === null || props.ageMs > props.staleAfterMs);
</script>
