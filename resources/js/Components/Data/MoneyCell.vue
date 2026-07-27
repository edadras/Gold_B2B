<template>
    <span class="num" :class="{ 'num--negative': negative }" dir="ltr">{{ text }}</span>
</template>

<script setup>
import { computed } from 'vue';

import { rial, rialCompact } from '../../lib/format.js';

/** A rial amount. See WeightCell for why `dir="ltr"` is mandatory. */
const props = defineProps({
    rial: { type: [Number, String, null], default: null },
    showUnit: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const negative = computed(() => props.rial !== null && Number(props.rial) < 0);

const text = computed(() => (props.compact
    ? rialCompact(props.rial)
    : rial(props.rial, { unit: props.showUnit })));
</script>
