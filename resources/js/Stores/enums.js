/**
 * The platform's enum catalogue, fetched once per page load — doc §2.17.
 *
 * `GET /meta/enums` carries an ETag and a one-hour private cache directive, so
 * the browser answers the second and later loads from its own cache; this store
 * additionally collapses concurrent callers onto ONE in-flight request, because
 * four forms mounting at the same moment must not send four requests for a
 * payload that is identical for all of them.
 *
 * FAILURE IS NOT FATAL. `catalogue` starts empty and stays empty if the fetch
 * fails; every helper in `lib/enum-catalogue.js` degrades to the raw value. A
 * member with a flaky connection gets a form whose labels read `PURITY_MISMATCH`
 * rather than a screen that will not render.
 *
 * @module Stores/enums
 */

import { reactive } from 'vue';

import { labelFor, mapFor, optionsFor } from '../lib/enum-catalogue.js';
import { useApi } from './panel.js';

export const enums = reactive({
    /** @type {Record<string, Array<{value: string, label: string, label_en: string}>>} */
    catalogue: {},
    loaded: false,
    failed: false,
});

/** @type {Promise<object>|null} */
let inFlight = null;

/** Fetch the catalogue if it is not already here or on its way. */
export function loadEnums() {
    if (enums.loaded) {
        return Promise.resolve(enums.catalogue);
    }

    if (inFlight !== null) {
        return inFlight;
    }

    const api = useApi();

    inFlight = api.get('/meta/enums')
        .then(({ data }) => {
            enums.catalogue = data && typeof data === 'object' ? data : {};
            enums.loaded = true;
            enums.failed = false;
            return enums.catalogue;
        })
        .catch(() => {
            // Deliberately silent: a missing label list is a degraded form, not
            // an error the member can act on, and a toast for it on every
            // screen would train people to dismiss toasts.
            enums.failed = true;
            return enums.catalogue;
        })
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

/** Options for a `<select>`, e.g. `enumOptions('dispute_type')`. */
export function enumOptions(key) {
    return optionsFor(enums.catalogue, key);
}

/** `{VALUE: label}`, for `StatusBadge`'s `map` prop. */
export function enumMap(key) {
    return mapFor(enums.catalogue, key);
}

/** One label, falling back to the raw value. */
export function enumLabel(key, value) {
    return labelFor(enums.catalogue, key, value);
}
