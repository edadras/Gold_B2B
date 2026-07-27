<template>
    <span v-if="ageMs !== null" class="stale" :class="{ 'stale--is-stale': stale }" dir="rtl">
        <span class="stale__dot" />
        <span v-if="stale">{{ label }}</span>
        <span v-else>زنده</span>
    </span>
</template>

<script setup>
import { computed } from 'vue';

import { formatAge } from '../../lib/jalali.js';

/**
 * Staleness indicator — a hard requirement, not a nicety.
 *
 * Doc §1.6: anything older than 30 seconds is greyed and labelled with its age.
 * A trading screen showing a confident price that stopped updating half a
 * minute ago is worse than one showing nothing, because the trader acts on it.
 * Components that own live figures put this badge next to them AND add the
 * `is-stale` class to the figure itself, so the greying and the label agree.
 */
const props = defineProps({
    ageMs: { type: Number, default: null },
    staleAfterMs: { type: Number, default: 30000 },
});

const stale = computed(() => props.ageMs === null || props.ageMs > props.staleAfterMs);
const label = computed(() => (Number.isFinite(props.ageMs) ? formatAge(props.ageMs) : 'بدون داده'));
</script>
