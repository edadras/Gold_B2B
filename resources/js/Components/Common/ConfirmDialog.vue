<template>
    <div v-if="open" class="modal-backdrop" @click.self="emit('cancel')">
        <div class="modal" role="dialog" aria-modal="true" dir="rtl" @keydown="onKey">
            <h2 class="modal__title">{{ title }}</h2>
            <div class="modal__body">
                <slot />
            </div>
            <div class="modal__actions">
                <button ref="confirmButton" class="btn btn--primary" type="button" @click="emit('confirm')">
                    {{ confirmLabel }}
                </button>
                <button class="btn btn--ghost" type="button" @click="emit('cancel')">انصراف</button>
            </div>
            <p class="modal__hint">Enter تأیید · Esc انصراف</p>
        </div>
    </div>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue';

/**
 * Confirmation for irreversible actions — order submission and cancel-all.
 *
 * Focus moves to the confirm button when the dialog opens, so Enter confirms
 * and Esc cancels without reaching for the mouse. The key handler is bound to
 * the dialog element rather than to `window`, so it cannot fire while the
 * dialog is closed and cannot race the terminal's global shortcuts.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'تأیید' },
    confirmLabel: { type: String, default: 'تأیید' },
});

const emit = defineEmits(['confirm', 'cancel']);

const confirmButton = ref(null);

watch(() => props.open, async (open) => {
    if (open) {
        await nextTick();
        if (confirmButton.value) {
            confirmButton.value.focus();
        }
    }
});

function onKey(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        emit('cancel');
    }
}
</script>
