<template>
    <figure class="chart" dir="rtl">
        <figcaption class="chart__caption">
            <span>نمودار قیمت</span>
            <div class="chart__ranges">
                <button
                    v-for="range in RANGES"
                    :key="range.key"
                    class="chart__range"
                    :class="{ 'is-active': range.key === active }"
                    type="button"
                    @click="$emit('range', range.key)"
                >{{ range.label }}</button>
            </div>
        </figcaption>

        <svg
            v-if="points.length > 1"
            class="chart__svg"
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            preserveAspectRatio="none"
            role="img"
            aria-label="نمودار قیمت"
        >
            <polyline class="chart__area" :points="areaPoints" />
            <polyline class="chart__line" :points="linePoints" />
        </svg>

        <p v-else class="chart__empty muted">داده شمعی کافی برای رسم نمودار موجود نیست.</p>

        <div v-if="points.length > 1" class="chart__axis" dir="ltr">
            <span class="num">{{ rial(low) }}</span>
            <span class="num">{{ rial(high) }}</span>
        </div>
    </figure>
</template>

<script setup>
/**
 * A close-price line drawn as inline SVG.
 *
 * No charting library: the panel must build with no network access to a CDN,
 * and a close-price line over 120 candles is a `polyline`. The chart is scaled
 * to the visible range rather than to zero, which is the convention for price
 * series — a gold price plotted from zero is a flat line.
 *
 * Float arithmetic here is deliberate and safe: these are SVG coordinates, not
 * money. The prices themselves are never rounded — only their pixel positions.
 */
import { computed } from 'vue';

import { rial } from '../../lib/format.js';

const WIDTH = 600;
const HEIGHT = 160;

const RANGES = [
    { key: '1m', label: '۱د' },
    { key: '1h', label: '۱س' },
    { key: '1d', label: '۱ر' },
    { key: '1w', label: '۱ه' },
    { key: '1M', label: '۱م' },
];

const props = defineProps({
    candles: { type: Array, default: () => [] },
    active: { type: String, default: '1m' },
});

defineEmits(['range']);

const points = computed(() => props.candles
    .map((candle) => Number(candle.close_rial))
    .filter((value) => Number.isFinite(value)));

const high = computed(() => (points.value.length ? Math.max(...points.value) : 0));
const low = computed(() => (points.value.length ? Math.min(...points.value) : 0));

const coordinates = computed(() => {
    const span = high.value - low.value || 1;
    const step = points.value.length > 1 ? WIDTH / (points.value.length - 1) : WIDTH;

    return points.value.map((value, index) => {
        // The page is RTL, and so is time on this chart: the newest candle sits
        // on the LEFT, matching how the tape below it reads.
        const x = WIDTH - index * step;
        const y = HEIGHT - ((value - low.value) / span) * (HEIGHT - 8) - 4;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
});

const linePoints = computed(() => coordinates.value.join(' '));

const areaPoints = computed(() => {
    if (coordinates.value.length === 0) {
        return '';
    }
    const first = coordinates.value[0].split(',')[0];
    const last = coordinates.value[coordinates.value.length - 1].split(',')[0];
    return `${first},${HEIGHT} ${linePoints.value} ${last},${HEIGHT}`;
});
</script>
