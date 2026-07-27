<template>
    <div class="toasts" dir="rtl">
        <div v-for="toast in panel.toasts" :key="toast.id" class="toast" :class="`toast--${toast.kind}`">
            <span>{{ toast.text }}</span>
            <button class="toast__close" type="button" @click="dismiss(toast.id)">×</button>
        </div>
    </div>
</template>

<script setup>
import { dismiss, panel } from '../../Stores/panel.js';
</script>
