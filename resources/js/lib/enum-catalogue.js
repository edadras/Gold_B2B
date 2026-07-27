/**
 * Reading `GET /meta/enums` — doc §2.17.
 *
 * WHY THE PANEL USES THIS RATHER THAN HARD-CODED LISTS
 * ---------------------------------------------------
 * The screens added here have to render option lists that are long and that
 * change: thirteen dispute types, sixteen document types, thirty-six webhook
 * event names. §2.17 exists precisely so that adding an enum member does not
 * require a new client build, and §1.11 states that adding one is a NON-breaking
 * change — which is only true if the client discovers the list instead of
 * shipping a copy that silently goes short.
 *
 * The short, stable enums the panel renders as BADGES stay hard-coded in
 * `StatusBadge` — a badge needs a COLOUR as well as a label, and a colour is a
 * client decision the server has no opinion about. This module covers the other
 * case: choosing a value from a list.
 *
 * Everything here is a pure function over an already-fetched catalogue, so it
 * is testable without a server; `Stores/enums.js` owns the fetching.
 *
 * The catalogue's shape, from EnumCatalogue::all():
 *   { "dispute_type": [ { value, label, label_en }, … ], … }
 *
 * @module lib/enum-catalogue
 */

/** An empty catalogue — the shape every helper here tolerates. */
export const EMPTY_CATALOGUE = Object.freeze({});

/**
 * The options for one enum, ready for a `<select>`.
 *
 * An unknown key returns `[]` rather than throwing: a panel whose enum fetch
 * failed must still render its form, with the field disabled or empty, instead
 * of blanking the screen.
 *
 * @returns {Array<{value: string, label: string}>}
 */
export function optionsFor(catalogue, key) {
    const cases = catalogue && catalogue[key];

    if (! Array.isArray(cases)) {
        return [];
    }

    return cases
        .filter((entry) => entry && typeof entry.value === 'string')
        .map((entry) => ({
            value: entry.value,
            // A case with no `label()` method on the server comes back with an
            // empty label; showing the raw value beats showing nothing.
            label: entry.label && entry.label !== '' ? entry.label : entry.value,
        }));
}

/**
 * The same list as a `{VALUE: label}` object — the shape `StatusBadge` and
 * `DataTable`'s `statusMap` take.
 */
export function mapFor(catalogue, key) {
    return Object.fromEntries(optionsFor(catalogue, key).map((option) => [option.value, option.label]));
}

/**
 * One label.
 *
 * Falls back to the value itself, never to an em dash: a status the panel has
 * never heard of is still information, and hiding it would make an unknown
 * state indistinguishable from an absent one.
 */
export function labelFor(catalogue, key, value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const match = optionsFor(catalogue, key).find((option) => option.value === value);

    return match ? match.label : String(value);
}

/**
 * Only the named values, in the order given.
 *
 * Used where the API accepts fewer values than the enum has cases — evidence
 * types exclude SYSTEM_LOG, roles exclude the platform ones — so the form
 * cannot offer a choice the server will reject.
 */
export function restrictTo(options, allowed) {
    const wanted = Array.isArray(allowed) ? allowed : [];
    const byValue = new Map(options.map((option) => [option.value, option]));

    return wanted
        .filter((value) => byValue.has(value))
        .map((value) => byValue.get(value));
}

/**
 * Group option values by a prefix before a separator — `trade.executed` and
 * `trade.settled` under `trade`.
 *
 * The webhook picker is thirty-six checkboxes; ungrouped, it is a wall. The
 * grouping is derived from the value rather than from a hand-kept map so a new
 * event name lands in the right group with no client change.
 *
 * @returns {Array<{group: string, options: Array<{value: string, label: string}>}>}
 */
export function groupByPrefix(options, separator = '.') {
    /** @type {Map<string, Array<{value: string, label: string}>>} */
    const groups = new Map();

    for (const option of options) {
        const index = option.value.indexOf(separator);
        const group = index === -1 ? '' : option.value.slice(0, index);

        if (! groups.has(group)) {
            groups.set(group, []);
        }

        groups.get(group).push(option);
    }

    return [...groups.entries()].map(([group, grouped]) => ({ group, options: grouped }));
}
