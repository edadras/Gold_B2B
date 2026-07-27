<template>
    <AppLayout :current="screen" :session="market.session" @navigate="navigate" @help="helpOpen = true">
        <Terminal v-if="screen === 'terminal'" @navigate="navigate" />
        <Ledger v-else-if="screen === 'ledger'" />
        <Settlements v-else-if="screen === 'settlements'" />
        <Orders v-else-if="screen === 'orders'" />
        <Trades v-else-if="screen === 'trades'" />
        <Lots v-else-if="screen === 'lots'" />
        <Counterparties v-else-if="screen === 'counterparties'" />
        <Reports v-else-if="screen === 'reports'" />
        <p v-else class="page__missing">صفحه یافت نشد.</p>
    </AppLayout>

    <ShortcutHelp :open="helpOpen" @close="helpOpen = false" />
</template>

<script setup>
/**
 * The panel root: routing and the one global shortcut that is not the
 * terminal's.
 *
 * ROUTING. A hand-rolled `history.pushState` router rather than vue-router.
 * The panel has eight flat screens and no nested routes or route guards; a
 * router would be more configuration than code. Every screen is a real URL the
 * server also serves (see PanelScreen), so a refresh or a bookmark works, and
 * the back button works because `popstate` is handled.
 *
 * `?` is bound here rather than in Terminal.vue so the shortcut help is
 * reachable from every screen, which is the only place a trader will look for
 * it when they are lost.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import AppLayout from './Layouts/AppLayout.vue';
import Counterparties from './Pages/Counterparties/Index.vue';
import Ledger from './Pages/Ledger/Index.vue';
import Lots from './Pages/Lots/Index.vue';
import Orders from './Pages/Orders/Index.vue';
import Reports from './Pages/Reports/Index.vue';
import Settlements from './Pages/Settlements/Index.vue';
import ShortcutHelp from './Components/Common/ShortcutHelp.vue';
import Terminal from './Pages/Market/Terminal.vue';
import Trades from './Pages/Trades/Index.vue';
import { isTypingIn, normaliseKey } from './Composables/useKeyboard.js';
import { market } from './Stores/market.js';
import { panel } from './Stores/panel.js';

const props = defineProps({
    initialScreen: { type: String, default: 'terminal' },
});

const screen = ref(props.initialScreen);
const helpOpen = ref(false);

const titles = computed(() => Object.fromEntries(panel.navigation.map((n) => [n.key, n.title])));

function navigate(key) {
    if (key === screen.value) {
        return;
    }
    screen.value = key;
    window.history.pushState({ screen: key }, '', `/app/${key}`);
    document.title = `${titles.value[key] || key} — Gold B2B`;
}

function syncFromLocation() {
    const match = window.location.pathname.match(/^\/app\/?([a-z-]*)/);
    screen.value = match && match[1] ? match[1] : 'terminal';
}

function onKey(event) {
    const key = normaliseKey(event);

    if (key === '?' && !isTypingIn(event.target)) {
        event.preventDefault();
        helpOpen.value = !helpOpen.value;
        return;
    }

    if (key === 'Escape' && helpOpen.value) {
        helpOpen.value = false;
    }
}

onMounted(() => {
    window.addEventListener('popstate', syncFromLocation);
    window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', syncFromLocation);
    window.removeEventListener('keydown', onKey);
});
</script>
