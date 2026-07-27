/**
 * How old is what I am looking at? — doc §1.6, applied to list screens.
 *
 * The terminal's figures each carry their own `Feed` and therefore their own
 * age. A `DataTable` fetches for itself, so this composable is the other half
 * of the same rule: the page records the moment of the last SUCCESSFUL load and
 * this ticks the age up from there, so the table's `StaleBadge` greys and
 * labels itself exactly like the depth ladder does.
 *
 * THE FAILURE BEHAVIOUR IS THE POINT. `touch()` is called from DataTable's
 * `@loaded`, which only fires on success. A refresh that fails therefore leaves
 * the rows on screen and lets the age keep climbing — never a blank table and
 * never a stale table that claims to be current. An OTC offer list is a page of
 * countdowns; showing it as fresh when it is two minutes old is how a member
 * accepts an offer that expired.
 *
 * @module Composables/useFreshness
 */

import { onBeforeUnmount, onMounted, ref } from 'vue';

import { panel } from '../Stores/panel.js';

/**
 * @param {object} [options]
 * @param {number} [options.tickMs] how often the age is recomputed
 * @returns {{ageMs: import('vue').Ref<number>, staleAfterMs: number, touch: () => void}}
 */
export function useFreshness({ tickMs = 1000 } = {}) {
    // Infinity, not 0: before the first successful load there is no data, and
    // claiming an age of zero would render the badge as «زنده».
    const ageMs = ref(Infinity);
    const lastUpdate = ref(null);

    let timer = null;

    function touch() {
        lastUpdate.value = Date.now();
        ageMs.value = 0;
    }

    onMounted(() => {
        timer = setInterval(() => {
            ageMs.value = lastUpdate.value === null ? Infinity : Date.now() - lastUpdate.value;
        }, tickMs);
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return {
        ageMs,
        staleAfterMs: panel.realtime.stale_after_ms || 30000,
        touch,
    };
}

/**
 * A periodic reload for the screens whose rows go out of date on their own —
 * OTC offers and RFQ quotes expire on a clock, disputes have reply deadlines.
 *
 * Much slower than the terminal's two seconds: these are pages of minutes, not
 * ticks, and polling a list endpoint every two seconds would cost the API far
 * more than the freshness is worth. The returned stopper is wired to unmount so
 * navigating away does not leave a poller running against a dead component.
 */
export function useAutoRefresh(reload, { intervalMs = 15000 } = {}) {
    let timer = null;

    onMounted(() => {
        timer = setInterval(() => {
            const result = reload();
            // Swallow a rejected reload here: DataTable has already reported it
            // to the member, and an unhandled rejection from a timer would be
            // reported a second time by the browser.
            if (result && typeof result.catch === 'function') {
                result.catch(() => {});
            }
        }, intervalMs);
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });
}
