/**
 * Panel-wide state: who is signed in, which member they act for, the API
 * client, and the toast queue.
 *
 * A plain `reactive()` object rather than Pinia — the panel has three stores
 * and none of them needs devtools time travel or module hot-swapping. Anything
 * that would live in a Pinia store lives here with the same shape, so lifting
 * it into one later is a rename.
 *
 * @module Stores/panel
 */

import { computed, reactive } from 'vue';

import { configureApi } from '../lib/api.js';

export const panel = reactive({
    user: null,
    organization: null,
    navigation: [],
    realtime: { websocket: null, poll_interval_ms: 2000, stale_after_ms: 30000 },
    locale: { timezone: 'Asia/Tehran', direction: 'rtl' },
    /** @type {Array<{id: number, kind: string, text: string}>} */
    toasts: [],
    connection: 'connecting',
});

let api = null;
let toastId = 0;

/** Wire the store from the blob the shell embedded. Called once, at boot. */
export function initialisePanel(bootstrap, csrfToken) {
    Object.assign(panel, {
        user: bootstrap.user || null,
        organization: bootstrap.organization || null,
        navigation: bootstrap.navigation || [],
        realtime: bootstrap.realtime || panel.realtime,
        locale: bootstrap.locale || panel.locale,
    });

    api = configureApi({
        baseUrl: (bootstrap.api && bootstrap.api.base_url) || '/api/v1',
        token: (bootstrap.api && bootstrap.api.token) || null,
        csrfToken,
    });

    api.onError((error) => {
        if (error.status === 401) {
            // The session died under us. Sending the browser to the sign-in
            // screen is better than letting every panel silently show an
            // error: the trader needs to know they are logged out.
            window.location.assign('/app/login');
            return;
        }

        if (error.status === 0) {
            panel.connection = 'disconnected';
            return;
        }

        panel.connection = 'connected';
    });

    panel.connection = panel.realtime.websocket ? 'connecting' : 'polling';

    return api;
}

export function useApi() {
    if (api === null) {
        throw new Error('Panel API used before initialisePanel()');
    }
    return api;
}

export function notify(text, kind = 'info') {
    const id = ++toastId;
    panel.toasts.push({ id, kind, text });
    setTimeout(() => {
        const index = panel.toasts.findIndex((t) => t.id === id);
        if (index !== -1) {
            panel.toasts.splice(index, 1);
        }
    }, kind === 'error' ? 8000 : 4000);
    return id;
}

export function dismiss(id) {
    const index = panel.toasts.findIndex((t) => t.id === id);
    if (index !== -1) {
        panel.toasts.splice(index, 1);
    }
}

/** Permission check mirroring Identity's, for hiding actions the API would refuse. */
export function can(permission) {
    const permissions = (panel.user && panel.user.permissions) || [];
    return permissions.includes('*') || permissions.includes(permission);
}

export const organizationName = computed(
    () => (panel.organization && panel.organization.display_name) || '—',
);
