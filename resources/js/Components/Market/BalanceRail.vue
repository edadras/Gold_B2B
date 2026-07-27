<template>
    <section class="rail-section" :class="{ 'is-stale': stale }" dir="rtl">
        <div class="rail-section__head">
            <h2 class="rail-section__title">موجودی</h2>
            <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
        </div>

        <template v-if="balances">
            <div class="balance">
                <div class="balance__label">طلا</div>
                <div class="balance__total num" dir="ltr">{{ grams(balances.gold.total_mg) }} گرم</div>
                <dl class="balance__breakdown">
                    <div><dt>آزاد</dt><dd class="num" dir="ltr">{{ grams(balances.gold.available_mg) }}</dd></div>
                    <div><dt>رزرو</dt><dd class="num" dir="ltr">{{ grams(balances.gold.reserved_mg) }}</dd></div>
                    <div><dt>تسویه</dt><dd class="num" dir="ltr">{{ grams(balances.gold.in_settlement_mg) }}</dd></div>
                    <div v-if="balances.gold.in_dispute_mg">
                        <dt>اختلاف</dt><dd class="num" dir="ltr">{{ grams(balances.gold.in_dispute_mg) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="balance">
                <div class="balance__label">ریال</div>
                <div class="balance__total num" dir="ltr">{{ rialCompact(balances.rial.net) }}</div>
                <dl class="balance__breakdown">
                    <div><dt>آزاد</dt><dd class="num" dir="ltr">{{ rialCompact(balances.rial.available) }}</dd></div>
                    <div><dt>رزرو</dt><dd class="num" dir="ltr">{{ rialCompact(balances.rial.reserved) }}</dd></div>
                    <div><dt>تسویه</dt><dd class="num" dir="ltr">{{ rialCompact(balances.rial.in_settlement) }}</dd></div>
                    <div v-if="balances.rial.payable">
                        <dt>پرداختنی</dt><dd class="num" dir="ltr">{{ rialCompact(balances.rial.payable) }}</dd>
                    </div>
                </dl>
            </div>
        </template>

        <p v-else class="muted">در حال بارگذاری موجودی…</p>
    </section>
</template>

<script setup>
/**
 * The balance rail — doc §1.3's left column.
 *
 * Free / reserved / in-settlement are shown separately and always. A single
 * "balance" figure is the number a member will misread: gold reserved against
 * an open order is not gold they can sell, and the difference is exactly what
 * an INSUFFICIENT_GOLD rejection is about.
 */
import { computed } from 'vue';

import StaleBadge from '../Common/StaleBadge.vue';
import { grams, rialCompact } from '../../lib/format.js';

const props = defineProps({
    balances: { type: Object, default: null },
    ageMs: { type: Number, default: null },
    staleAfterMs: { type: Number, default: 30000 },
});

const stale = computed(() => props.ageMs === null || props.ageMs > props.staleAfterMs);
</script>
