<template>
    <span class="num" dir="ltr">{{ text }}</span>
</template>

<script setup>
import { computed } from 'vue';

import { grams } from '../../lib/format.js';

/**
 * Weight in milligrams, always three decimals.
 *
 * `dir="ltr"` is not decoration. The page is RTL; a number placed in it without
 * an explicit direction is reordered by the bidi algorithm, and "1,247.320"
 * renders as "320.1,247" with the minus sign on the wrong end. The `.num` class
 * adds `font-variant-numeric: tabular-nums` so the digits are equal width and
 * the column actually lines up.
 */
const props = defineProps({
    mg: { type: [Number, String, null], default: null },
    showUnit: { type: Boolean, default: false },
});

const text = computed(() => grams(props.mg, { unit: props.showUnit }));
</script>
