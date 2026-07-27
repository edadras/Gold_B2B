<template>
    <span class="badge" :class="`badge--${tone}`">{{ label }}</span>
</template>

<script setup>
import { computed } from 'vue';

/**
 * A status pill.
 *
 * The tone is derived from the status VALUE, not passed in by each call site,
 * so the same status never appears green on one screen and grey on another.
 * Unknown values fall through to a neutral pill rather than throwing: §1.11
 * says adding an enum member is a NON-breaking change and the client must
 * tolerate one it has never seen.
 */
const props = defineProps({
    status: { type: [String, null], default: null },
    map: { type: Object, default: () => ({}) },
});

const TONES = {
    OPEN: 'info',
    PENDING: 'info',
    PARTIALLY_FILLED: 'info',
    AWAITING_PAYMENT: 'warn',
    AWAITING_DELIVERY: 'warn',
    OVERDUE: 'danger',
    DISPUTED: 'danger',
    REJECTED: 'danger',
    CANCELLED: 'muted',
    EXPIRED: 'muted',
    FILLED: 'good',
    SETTLED: 'good',
    COMPLETED: 'good',
    CONFIRMED: 'good',
    ACTIVE: 'good',
    AVAILABLE: 'good',
    RESERVED: 'warn',
    ON_HOLD: 'warn',
};

const LABELS = {
    OPEN: 'باز',
    PARTIALLY_FILLED: 'اجرای جزئی',
    FILLED: 'اجرا شده',
    CANCELLED: 'لغو شده',
    EXPIRED: 'منقضی',
    REJECTED: 'رد شده',
    PENDING: 'در انتظار',
    AWAITING_PAYMENT: 'در انتظار پرداخت',
    AWAITING_DELIVERY: 'در انتظار تحویل',
    OVERDUE: 'معوق',
    DISPUTED: 'در اختلاف',
    SETTLED: 'تسویه شده',
    COMPLETED: 'تکمیل شده',
    CONFIRMED: 'تأیید شده',
    ACTIVE: 'فعال',
    AVAILABLE: 'در دسترس',
    RESERVED: 'رزرو شده',
    ON_HOLD: 'متوقف',
};

const label = computed(() => props.map[props.status] || LABELS[props.status] || props.status || '—');
const tone = computed(() => TONES[props.status] || 'muted');
</script>
