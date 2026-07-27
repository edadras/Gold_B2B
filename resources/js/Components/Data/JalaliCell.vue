<template>
    <span class="num" dir="ltr">{{ text }}</span>
</template>

<script setup>
import { computed } from 'vue';

import { useFormatting } from '../../Composables/useFormatting.js';

/**
 * A Jalali date, converted client-side.
 *
 * The API offers a `_jalali` companion field, but list endpoints are fetched
 * with `?include_display=false` to keep payloads small, so the panel converts
 * locally with the same algorithm (see lib/jalali.js).
 */
const props = defineProps({
    iso: { type: [String, null], default: null },
    withTime: { type: Boolean, default: true },
});

const { jalali } = useFormatting();

const text = computed(() => jalali(props.iso, { withTime: props.withTime }));
</script>
