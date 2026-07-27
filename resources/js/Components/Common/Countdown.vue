<template>
    <span class="countdown" :class="`countdown--${band}`">
        <span class="num" dir="ltr">{{ text }}</span>
        <span v-if="band === 'expired'" class="countdown__word">منقضی</span>
    </span>
</template>

<script setup>
/**
 * Time left on an offer, a quote or a reply deadline.
 *
 * A sibling of `StaleBadge`, and for the same reason: `StaleBadge` says how old
 * the number on screen is, this says how long the number stays actionable. Both
 * are hard requirements of a screen where the member presses a button — an OTC
 * offer that expired forty seconds ago must not still look live, exactly as a
 * price that stopped updating must not.
 *
 * The countdown is recomputed on a one-second timer rather than derived once at
 * render, because the row is not re-fetched between ticks and a frozen "02:14"
 * is the same lie as a frozen price.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { expiryBand, formatCountdown, remainingMs } from '../../lib/negotiation.js';

const props = defineProps({
    /** ISO-8601 deadline, or null for "no deadline". */
    expiresAt: { type: [String, null], default: null },
});

const now = ref(Date.now());
let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));

const remaining = computed(() => remainingMs(props.expiresAt, now.value));
const band = computed(() => expiryBand(remaining.value));
const text = computed(() => formatCountdown(remaining.value));

defineExpose({ remaining, band });
</script>
