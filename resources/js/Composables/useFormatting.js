/**
 * Formatting helpers, re-exported for components — doc §1.2,
 * `Composables/useFormatting.ts`.
 *
 * A thin file on purpose. The formatting itself lives in `lib/format.js`, which
 * is framework-free and unit-tested under `node --test`; this only binds it to
 * the panel's timezone setting so components do not each pass it in.
 *
 * @module Composables/useFormatting
 */

import { bps, count, grams, purity, rial, rialCompact, signedGrams, signedRial } from '../lib/format.js';
import { formatAge, formatClock, formatJalali } from '../lib/jalali.js';
import { panel } from '../Stores/panel.js';

export function useFormatting() {
    const timezone = () => (panel.locale && panel.locale.timezone) || 'Asia/Tehran';

    return {
        grams,
        rial,
        rialCompact,
        purity,
        bps,
        count,
        signedGrams,
        signedRial,
        formatAge,
        jalali: (iso, options = {}) => formatJalali(iso, { timezone: timezone(), ...options }),
        clock: (iso, options = {}) => formatClock(iso, { timezone: timezone(), ...options }),
    };
}
