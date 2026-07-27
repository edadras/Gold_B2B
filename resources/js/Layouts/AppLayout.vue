<template>
    <div class="app" dir="rtl">
        <header class="app__bar">
            <div class="app__brand">Gold B2B</div>
            <div class="app__org">{{ organizationName }}</div>

            <span class="market-state" :class="`market-state--${sessionTone}`">
                <span class="market-state__dot" />
                {{ sessionLabel }}
            </span>

            <span class="app__clock num" dir="ltr">{{ now }}</span>

            <div class="app__spacer" />

            <span class="app__transport" :title="transportTitle">{{ transportLabel }}</span>

            <nav class="app__nav">
                <a
                    v-for="item in panel.navigation"
                    :key="item.key"
                    class="app__nav-link"
                    :class="{ 'is-active': item.key === current }"
                    :href="`/app/${item.path}`"
                    @click.prevent="$emit('navigate', item.key)"
                >{{ item.title }}</a>
            </nav>

            <button class="btn btn--ghost btn--sm" type="button" @click="$emit('help')">؟</button>
            <button class="btn btn--ghost btn--sm" type="button" @click="signOut">خروج</button>
        </header>

        <main class="app__main">
            <slot />
        </main>

        <ToastStack />
    </div>
</template>

<script setup>
/**
 * The chrome around every screen — doc §1.3's top bar.
 *
 * The bar carries three things a trader checks without thinking: which member
 * they are acting for, whether the market is open, and whether the data is
 * arriving over a socket or by polling. The last one is normally invisible
 * plumbing, but in this deployment there is no WebSocket server, so saying
 * "polling every 2 s" out loud is more honest than pretending it is live.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import ToastStack from '../Components/Common/ToastStack.vue';
import { organizationName, panel, useApi } from '../Stores/panel.js';
import { useFormatting } from '../Composables/useFormatting.js';

const props = defineProps({
    current: { type: String, default: 'terminal' },
    session: { type: Object, default: null },
});

defineEmits(['navigate', 'help']);

const { clock } = useFormatting();

const now = ref(clock(new Date()));
let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = clock(new Date());
    }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));

const sessionLabel = computed(() => {
    const state = props.session && props.session.state;
    return {
        OPEN: 'بازار باز',
        PRE_OPEN: 'پیش‌گشایش',
        PAUSED: 'بازار متوقف',
        CLOSED: 'بازار بسته',
    }[state] || 'وضعیت نامشخص';
});

const sessionTone = computed(() => {
    const state = props.session && props.session.state;
    return { OPEN: 'open', PRE_OPEN: 'warn', PAUSED: 'warn', CLOSED: 'closed' }[state] || 'muted';
});

const transportLabel = computed(() => (panel.realtime.websocket
    ? 'بلادرنگ'
    : `به‌روزرسانی هر ${Math.round((panel.realtime.poll_interval_ms || 2000) / 1000)} ثانیه`));

const transportTitle = computed(() => (panel.realtime.websocket
    ? 'داده از طریق WebSocket دریافت می‌شود.'
    : 'سرویس WebSocket در دسترس نیست؛ داده با فراخوانی دوره‌ای REST به‌روز می‌شود.'));

async function signOut() {
    const api = useApi();
    try {
        await api.post('/auth/logout');
    } catch {
        // A failed API logout must not trap the user in the panel; the browser
        // session is closed either way.
    }
    try {
        await api.panel('DELETE', '/app/session');
    } finally {
        window.location.assign('/app/login');
    }
}
</script>
