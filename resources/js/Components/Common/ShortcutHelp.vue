<template>
    <div v-if="open" class="modal-backdrop" @click.self="$emit('close')">
        <div class="modal modal--wide" role="dialog" aria-modal="true" dir="rtl">
            <h2 class="modal__title">راهنمای شورتکات</h2>
            <table class="table table--plain">
                <tbody>
                    <tr v-for="shortcut in SHORTCUTS" :key="shortcut.keys">
                        <td class="shortcut__keys"><kbd dir="ltr">{{ shortcut.keys }}</kbd></td>
                        <td>{{ shortcut.label }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="modal__actions">
                <button class="btn btn--ghost" type="button" @click="$emit('close')">بستن</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { SHORTCUTS } from '../../Composables/useKeyboard.js';

defineProps({ open: { type: Boolean, default: false } });
defineEmits(['close']);
</script>
