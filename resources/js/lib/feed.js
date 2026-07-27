/**
 * Live-data transport — doc §1.6.
 *
 * The doc specifies Laravel Echo over Reverb. Reverb is not installed in this
 * environment (`config/broadcasting.php` defaults to the `log` driver, which
 * has no socket a browser can open), so this module implements the same
 * contract two ways and picks at runtime:
 *
 *   · if `window.Echo` exists AND the bootstrap payload carries a websocket
 *     key, subscribe to the channel and refresh on every event; otherwise
 *   · poll the REST endpoint on an interval.
 *
 * Either way the consumer sees one shape: `{ data, lastUpdate, transport }`
 * plus staleness. Switching from polling to sockets when the broadcasting
 * module lands is a configuration change, not a rewrite of every screen.
 *
 * STALENESS IS NOT OPTIONAL. A trading screen that shows a frozen price with no
 * indication is worse than one that shows nothing: the trader acts on it. Every
 * feed exposes `ageMs`, and the components grey the figure and print the age
 * once it passes `staleAfterMs` (30 s per the doc).
 *
 * @module lib/feed
 */

export const DEFAULT_POLL_INTERVAL_MS = 2000;
export const DEFAULT_STALE_AFTER_MS = 30000;

/**
 * One live data source.
 *
 * Deliberately framework-free: it owns state and notifies subscribers, and a
 * Vue composable adapts it to reactivity in four lines. That is the seam that
 * keeps this file testable under `node --test`.
 */
export class Feed {
    /**
     * @param {object} options
     * @param {() => Promise<any>} options.load REST fetch, also used to re-sync
     *   after a socket reconnect (doc §1.6).
     * @param {string|null} [options.channel] broadcast channel name
     * @param {string[]} [options.events] event names to listen for
     * @param {number} [options.intervalMs]
     * @param {number} [options.staleAfterMs]
     * @param {object|null} [options.echo] an Echo instance, or null to poll
     * @param {() => number} [options.now] injectable clock, for tests
     */
    constructor({
        load,
        channel = null,
        events = [],
        intervalMs = DEFAULT_POLL_INTERVAL_MS,
        staleAfterMs = DEFAULT_STALE_AFTER_MS,
        echo = null,
        now = () => Date.now(),
    }) {
        this.load = load;
        this.channel = channel;
        this.events = events;
        this.intervalMs = intervalMs;
        this.staleAfterMs = staleAfterMs;
        this.echo = echo;
        this.now = now;

        this.data = null;
        this.error = null;
        this.lastUpdate = null;
        this.running = false;
        this.inFlight = false;
        this.timer = null;
        this.subscription = null;
        /** @type {Set<(feed: Feed) => void>} */
        this.listeners = new Set();
    }

    /** 'websocket' when a live channel is attached, 'poll' otherwise. */
    get transport() {
        return this.subscription === null ? 'poll' : 'websocket';
    }

    /** Milliseconds since the last successful update; Infinity before the first. */
    get ageMs() {
        return this.lastUpdate === null ? Infinity : this.now() - this.lastUpdate;
    }

    get isStale() {
        return this.ageMs > this.staleAfterMs;
    }

    subscribe(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }

    start() {
        if (this.running) {
            return this;
        }
        this.running = true;

        void this.refresh();

        if (this.echo && this.channel) {
            this.subscription = this.echo.channel(this.channel);
            for (const event of this.events) {
                this.subscription.listen(event, (payload) => this.#accept(payload));
            }
            // Even on a socket, a slow heartbeat catches a silently dropped
            // connection: no event and no poll means the age climbs and the UI
            // greys itself, rather than showing a confident stale price.
            this.timer = setInterval(() => void this.refresh(), this.staleAfterMs);
        } else {
            this.timer = setInterval(() => void this.refresh(), this.intervalMs);
        }

        return this;
    }

    stop() {
        this.running = false;

        if (this.timer !== null) {
            clearInterval(this.timer);
            this.timer = null;
        }

        if (this.subscription !== null && this.echo && this.channel) {
            this.echo.leave(this.channel);
            this.subscription = null;
        }

        return this;
    }

    /** Fetch now. Overlapping calls collapse into the one already in flight. */
    async refresh() {
        if (this.inFlight) {
            return this.data;
        }
        this.inFlight = true;

        try {
            const payload = await this.load();
            this.#accept(payload);
            this.error = null;
        } catch (error) {
            // The previous value is KEPT on failure, and the age keeps rising.
            // Blanking the ladder on one dropped request would be a worse lie
            // than showing the last known book with its age attached.
            if (error && error.name !== 'AbortError') {
                this.error = error;
                this.#emit();
            }
        } finally {
            this.inFlight = false;
        }

        return this.data;
    }

    #accept(payload) {
        if (payload === null || payload === undefined) {
            return;
        }
        this.data = payload;
        this.lastUpdate = this.now();
        this.#emit();
    }

    #emit() {
        for (const listener of this.listeners) {
            listener(this);
        }
    }
}

/**
 * Resolve a shared Echo instance from the bootstrap payload, or null.
 *
 * Never throws: a panel that cannot open a socket must still trade by polling.
 */
export function resolveEcho(realtime) {
    if (!realtime || !realtime.websocket) {
        return null;
    }

    // Echo is loaded by the broadcasting module when it is present; the panel
    // does not bundle it, so that its absence costs nothing.
    const echo = typeof window !== 'undefined' ? window.Echo : null;

    return echo || null;
}
