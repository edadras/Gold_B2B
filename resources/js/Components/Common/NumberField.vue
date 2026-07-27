<template>
    <label class="field">
        <span class="field__label">{{ label }}</span>
        <input
            ref="input"
            class="input num"
            dir="ltr"
            type="text"
            inputmode="decimal"
            :value="display"
            :placeholder="placeholder"
            @input="onInput"
            @blur="onBlur"
        >
        <span v-if="hint" class="field__hint">{{ hint }}</span>
        <span v-if="error" class="field__error">{{ error }}</span>
    </label>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

import { fromScaledInt, group, normalizeDigits, toScaledInt } from '../../lib/numeric-input.js';

/**
 * A numeric field that speaks Persian and returns an INTEGER.
 *
 * The value bound by the parent is a scaled integer (milligrams, or rial at
 * scale 0) held as a BigInt — never a float, and never a partially-parsed
 * string. Typing is kept in a separate `draft` so that "78," and "250." are
 * legal intermediate states; parsing happens on every keystroke but only
 * commits when it succeeds.
 *
 * Persian and Arabic digits are normalised in place, so the field shows Latin
 * digits as the trader types Persian ones and there is no ambiguity about what
 * was entered.
 */
const props = defineProps({
    modelValue: { type: [BigInt, Number, String, null], default: null },
    label: { type: String, default: '' },
    scale: { type: Number, default: 0 },
    placeholder: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    /** Re-group with thousands separators when focus leaves. */
    groupOnBlur: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue', 'enter']);

const input = ref(null);
const draft = ref('');
const editing = ref(false);

const canonical = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
        return '';
    }
    const text = fromScaledInt(BigInt(props.modelValue), props.scale);
    return props.groupOnBlur ? group(text) : text;
});

const display = computed(() => (editing.value ? draft.value : canonical.value));

watch(() => props.modelValue, () => {
    if (!editing.value) {
        draft.value = canonical.value;
    }
});

function onInput(event) {
    editing.value = true;
    const normalised = normalizeDigits(event.target.value);
    draft.value = normalised;
    event.target.value = normalised;

    if (normalised.trim() === '') {
        emit('update:modelValue', null);
        return;
    }

    try {
        emit('update:modelValue', toScaledInt(normalised, props.scale));
    } catch {
        // An in-progress value like "78," or "." — keep the draft, do not
        // commit, and do not shout at the user mid-keystroke.
    }
}

function onBlur() {
    editing.value = false;
    draft.value = canonical.value;
}

function focus() {
    if (input.value) {
        input.value.focus();
        input.value.select();
    }
}

defineExpose({ focus });
</script>
