/**
 * Market state for the trading terminal — instruments, the selected book, the
 * tape, open orders and balances.
 *
 * Every live figure arrives through a `Feed`, so each one carries its own age
 * and staleness independently. That matters: the depth ladder and the balance
 * rail refresh on different cadences, and one going stale must not grey the
 * other.
 *
 * @module Stores/market
 */

import { reactive, ref } from 'vue';

import { Feed, resolveEcho } from '../lib/feed.js';
import { panel, useApi } from './panel.js';

export const market = reactive({
    /** @type {Array<object>} */
    instruments: [],
    /** @type {string|null} */
    selected: null,
    /** @type {object|null} */
    quote: null,
    depth: { bids: [], asks: [], spread_rial: null, mid_price_rial: null },
    /** @type {Array<object>} */
    tape: [],
    /** @type {Array<object>} */
    candles: [],
    /** @type {Array<object>} */
    openOrders: [],
    balances: null,
    session: null,
    loading: true,
});

/** Per-source ages, so each panel can grey itself independently. */
export const ages = reactive({
    depth: Infinity,
    quote: Infinity,
    tape: Infinity,
    orders: Infinity,
    balances: Infinity,
});

const feeds = new Map();

export const staleAfterMs = ref(30000);

function track(name, feed) {
    feeds.set(name, feed);
    feed.subscribe(() => {
        ages[name] = feed.ageMs;
    });
    return feed;
}

/** Recompute ages on a timer so the "12 ثانیه پیش" label actually ticks. */
let ageTimer = null;

export function selectedInstrument() {
    return market.instruments.find((i) => i.code === market.selected) || null;
}

export async function loadInstruments() {
    const api = useApi();
    const { data } = await api.get('/instruments');
    market.instruments = data || [];

    if (market.selected === null && market.instruments.length > 0) {
        market.selected = market.instruments[0].code;
    }

    return market.instruments;
}

/**
 * Start every terminal feed for the selected instrument.
 *
 * Returns a teardown function. The caller (Terminal.vue) calls it on unmount
 * and on instrument change, so switching books does not leave the previous
 * one's poller running.
 */
export function startTerminalFeeds() {
    const api = useApi();
    const echo = resolveEcho(panel.realtime);
    const interval = panel.realtime.poll_interval_ms || 2000;
    const staleAfter = panel.realtime.stale_after_ms || 30000;
    staleAfterMs.value = staleAfter;

    const code = market.selected;
    if (!code) {
        return () => {};
    }

    const common = { intervalMs: interval, staleAfterMs: staleAfter, echo };

    track('depth', new Feed({
        ...common,
        channel: `market.${code}`,
        events: ['.depth.updated'],
        load: async () => (await api.get(`/market/depth/${code}`, { levels: 10 })).data,
    })).subscribe((feed) => {
        if (feed.data) {
            market.depth = feed.data;
        }
    });

    track('quote', new Feed({
        ...common,
        channel: `market.${code}`,
        events: ['.quote.updated'],
        load: async () => (await api.get(`/market/quotes/${code}`)).data,
    })).subscribe((feed) => {
        if (feed.data) {
            market.quote = feed.data;
        }
    });

    track('tape', new Feed({
        ...common,
        channel: `market.${code}`,
        events: ['.trade.executed'],
        load: async () => (await api.get(`/market/trades/${code}`, { limit: 20 })).data,
    })).subscribe((feed) => {
        if (Array.isArray(feed.data)) {
            market.tape = feed.data;
        }
    });

    track('orders', new Feed({
        ...common,
        channel: `organization.${panel.organization ? panel.organization.id : 0}`,
        events: ['.order.updated'],
        load: async () => (await api.get('/orders', {
            filter: { status: 'OPEN', instrument: code },
            limit: 50,
        })).data,
    })).subscribe((feed) => {
        if (Array.isArray(feed.data)) {
            market.openOrders = feed.data;
        }
    });

    track('balances', new Feed({
        ...common,
        // Balances move on settlement, not on every tick; a slower cadence
        // keeps the poller off the ledger's hot path.
        intervalMs: Math.max(interval * 5, 5000),
        channel: `organization.${panel.organization ? panel.organization.id : 0}`,
        events: ['.balance.updated'],
        load: async () => (await api.get('/balances')).data,
    })).subscribe((feed) => {
        if (feed.data) {
            market.balances = feed.data;
        }
    });

    for (const feed of feeds.values()) {
        feed.start();
    }

    // Candles are not a live feed — the chart redraws on a much slower beat.
    void api.get(`/market/candles/${code}`, { interval: '1m', limit: 120 })
        .then(({ data }) => {
            market.candles = Array.isArray(data) ? data : [];
        })
        .catch(() => {
            market.candles = [];
        });

    void api.get(`/market/sessions/${code}`)
        .then(({ data }) => {
            market.session = data;
        })
        .catch(() => {
            market.session = null;
        });

    market.loading = false;

    ageTimer = setInterval(() => {
        for (const [name, feed] of feeds.entries()) {
            ages[name] = feed.ageMs;
        }
    }, 1000);

    return stopTerminalFeeds;
}

export function stopTerminalFeeds() {
    for (const feed of feeds.values()) {
        feed.stop();
    }
    feeds.clear();

    if (ageTimer !== null) {
        clearInterval(ageTimer);
        ageTimer = null;
    }
}

/** Force every feed to re-read from REST — doc §1.6's post-reconnect re-sync. */
export function resyncAll() {
    return Promise.all([...feeds.values()].map((feed) => feed.refresh()));
}

export function refreshOrders() {
    const feed = feeds.get('orders');
    return feed ? feed.refresh() : Promise.resolve();
}

export function refreshBalances() {
    const feed = feeds.get('balances');
    return feed ? feed.refresh() : Promise.resolve();
}
