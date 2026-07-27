<template>
    <section class="rail-section" dir="rtl">
        <h2 class="rail-section__title">ابزارها</h2>
        <ul class="instruments">
            <li v-for="(instrument, index) in instruments" :key="instrument.code">
                <button
                    class="instrument"
                    :class="{ 'is-selected': instrument.code === selected, 'is-halted': !instrument.is_tradable }"
                    type="button"
                    @click="$emit('select', instrument.code)"
                >
                    <span class="instrument__marker">{{ instrument.code === selected ? '▸' : '' }}</span>
                    <span class="instrument__code" dir="ltr">{{ instrument.code }}</span>
                    <kbd v-if="index < 9" class="instrument__key" dir="ltr">{{ index + 1 }}</kbd>
                </button>
            </li>
        </ul>
        <p v-if="instruments.length === 0" class="muted">ابزاری فعال نیست.</p>
    </section>
</template>

<script setup>
/**
 * The instrument rail. Numbers 1..9 double as the keyboard selector from
 * §1.3, and the digit is shown on the row so the trader can learn it without
 * opening the help overlay.
 */
defineProps({
    instruments: { type: Array, default: () => [] },
    selected: { type: String, default: null },
});

defineEmits(['select']);
</script>
