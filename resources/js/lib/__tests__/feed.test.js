/**
 * The live-data transport — doc §1.6.
 *
 * The behaviour worth pinning down is not "does it fetch" but what it does when
 * the fetch stops working: a trading screen that keeps showing the last price
 * with no age attached is how a trader ends up hitting a bid that vanished
 * thirty seconds ago.
 *
 *     node --test resources/js/lib/__tests__/feed.test.js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { Feed } from '../feed.js';
import { ApiError, buildQuery, uuid } from '../api.js';

/** A hand-cranked clock, so staleness can be tested without waiting 30 s. */
function clock(start = 1_000_000) {
    let t = start;
    return { now: () => t, advance: (ms) => { t += ms; } };
}

describe('Feed', () => {
    it('is stale before the first load, and fresh after it', async () => {
        const time = clock();
        const feed = new Feed({ load: async () => ({ price: 1 }), now: time.now });

        assert.equal(feed.isStale, true);
        assert.equal(feed.ageMs, Infinity);

        await feed.refresh();

        assert.deepEqual(feed.data, { price: 1 });
        assert.equal(feed.isStale, false);
        assert.equal(feed.ageMs, 0);
    });

    it('goes stale after the 30-second threshold from the doc', async () => {
        const time = clock();
        const feed = new Feed({ load: async () => ({ price: 1 }), now: time.now });

        await feed.refresh();

        time.advance(29_000);
        assert.equal(feed.isStale, false);

        time.advance(2_000);
        assert.equal(feed.isStale, true);
        assert.equal(feed.ageMs, 31_000);
    });

    it('keeps the last good value when a refresh fails, and lets the age climb', async () => {
        const time = clock();
        let shouldFail = false;

        const feed = new Feed({
            load: async () => {
                if (shouldFail) {
                    throw new ApiError(503, { error: { code: 'SERVICE_UNAVAILABLE', message: 'x' } });
                }
                return { price: 78480000 };
            },
            now: time.now,
        });

        await feed.refresh();
        shouldFail = true;
        time.advance(40_000);
        await feed.refresh();

        // The ladder is still on screen — but marked stale, which is the point.
        assert.deepEqual(feed.data, { price: 78480000 });
        assert.equal(feed.isStale, true);
        assert.equal(feed.error.code, 'SERVICE_UNAVAILABLE');
    });

    it('collapses overlapping refreshes instead of stacking requests', async () => {
        let calls = 0;
        const feed = new Feed({
            load: async () => {
                calls += 1;
                await new Promise((resolve) => setTimeout(resolve, 5));
                return { calls };
            },
        });

        await Promise.all([feed.refresh(), feed.refresh(), feed.refresh()]);

        assert.equal(calls, 1);
    });

    it('reports polling when no Echo instance is available', () => {
        const feed = new Feed({ load: async () => ({}) });
        assert.equal(feed.transport, 'poll');
    });

    it('reports websocket and applies pushed events when Echo is available', async () => {
        const time = clock();
        const handlers = {};
        const echo = {
            channel: () => ({
                listen: (event, handler) => {
                    handlers[event] = handler;
                },
            }),
            leave: () => {},
        };

        const feed = new Feed({
            load: async () => ({ source: 'rest' }),
            channel: 'market.GOLD-995-T0',
            events: ['.depth.updated'],
            echo,
            now: time.now,
        });

        feed.start();
        assert.equal(feed.transport, 'websocket');

        time.advance(45_000);
        assert.equal(feed.isStale, true);

        handlers['.depth.updated']({ source: 'socket' });

        assert.deepEqual(feed.data, { source: 'socket' });
        assert.equal(feed.isStale, false);

        feed.stop();
    });

    it('notifies subscribers on every accepted update', async () => {
        const seen = [];
        const feed = new Feed({ load: async () => ({ n: seen.length }) });
        feed.subscribe((f) => seen.push(f.data));

        await feed.refresh();
        await feed.refresh();

        assert.equal(seen.length, 2);
    });
});

describe('api helpers', () => {
    it('encodes filters in the §1.9 bracket syntax', () => {
        const query = buildQuery({
            filter: { side: 'BUY', instrument: 'GOLD-995-T0', empty: '' },
            sort: '-executed_at',
            limit: 50,
            cursor: null,
        });

        assert.equal(
            decodeURIComponent(query),
            '?filter[side]=BUY&filter[instrument]=GOLD-995-T0&sort=-executed_at&limit=50',
        );
    });

    it('returns an empty string rather than a bare question mark', () => {
        assert.equal(buildQuery({}), '');
        assert.equal(buildQuery({ cursor: null }), '');
    });

    it('generates RFC 4122 v4 idempotency keys', () => {
        const key = uuid();
        assert.match(key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        assert.notEqual(uuid(), uuid());
    });

    it('classifies which API errors are worth retrying', () => {
        const conflict = new ApiError(409, { error: { code: 'IDEMPOTENCY_IN_PROGRESS' } });
        const rejected = new ApiError(422, { error: { code: 'INSUFFICIENT_GOLD' } });

        assert.equal(conflict.isRetryable, true);
        assert.equal(rejected.isRetryable, false);
        assert.equal(rejected.code, 'INSUFFICIENT_GOLD');
    });
});
