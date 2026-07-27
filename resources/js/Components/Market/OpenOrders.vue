<template>
    <section class="open-orders" dir="rtl">
        <header class="open-orders__header">
            <h2>سفارش‌های باز ({{ orders.length }})</h2>
            <button
                class="btn btn--ghost btn--sm"
                type="button"
                :disabled="orders.length === 0"
                @click="$emit('cancelAll')"
            >
                لغو همه <kbd dir="ltr">Ctrl+A</kbd>
            </button>
        </header>

        <table class="table table--dense">
            <tbody>
                <tr v-for="order in orders" :key="order.id">
                    <td class="num" dir="ltr">{{ order.order_code }}</td>
                    <td :class="order.side === 'BUY' ? 'is-buy' : 'is-sell'">
                        {{ order.side === 'BUY' ? 'خرید' : 'فروش' }}
                    </td>
                    <td class="align-end"><WeightCell :mg="order.remaining_mg" /></td>
                    <td class="align-end"><MoneyCell :rial="order.price_rial" /></td>
                    <td><StatusBadge :status="order.status" /></td>
                    <td class="align-end">
                        <button
                            class="btn btn--icon"
                            type="button"
                            title="لغو سفارش"
                            @click="$emit('cancel', order)"
                        >✕</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="orders.length === 0" class="muted">سفارش بازی ندارید.</p>
    </section>
</template>

<script setup>
import MoneyCell from '../Data/MoneyCell.vue';
import StatusBadge from '../Data/StatusBadge.vue';
import WeightCell from '../Data/WeightCell.vue';

defineProps({ orders: { type: Array, default: () => [] } });
defineEmits(['cancel', 'cancelAll']);
</script>
