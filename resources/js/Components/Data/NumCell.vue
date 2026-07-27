<template>
    <span class="num" dir="ltr"><slot>{{ value ?? '—' }}</slot></span>
</template>

<script setup>
/** Any bare number in a table. Exists so no component hand-rolls `dir="ltr"`. */
defineProps({ value: { type: [String, Number, null], default: null } });
</script>
