/**
 * Reading `/meta/enums` — §2.17, and §1.11's promise that adding an enum member
 * is a NON-breaking change.
 *
 * The behaviour worth pinning is what happens when the catalogue is absent or
 * incomplete: every helper has to degrade to something a member can still read,
 * because the alternative is a form with an empty dropdown and no explanation.
 *
 *     node --test resources/js/lib/__tests__/enum-catalogue.test.js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    EMPTY_CATALOGUE,
    groupByPrefix,
    labelFor,
    mapFor,
    optionsFor,
    restrictTo,
} from '../enum-catalogue.js';

const catalogue = {
    dispute_type: [
        { value: 'PURITY_MISMATCH', label: 'اختلاف عیار', label_en: 'Purity mismatch' },
        { value: 'WEIGHT_MISMATCH', label: 'اختلاف وزن', label_en: 'Weight mismatch' },
    ],
    evidence_type: [
        { value: 'DOCUMENT', label: 'سند', label_en: 'Document' },
        { value: 'SYSTEM_LOG', label: 'لاگ سامانه', label_en: 'System log' },
    ],
    webhook_event_type: [
        { value: 'trade.executed', label: '', label_en: '' },
        { value: 'order.filled', label: '', label_en: '' },
        { value: 'order.cancelled', label: '', label_en: '' },
    ],
};

describe('optionsFor', () => {
    it('returns value/label pairs ready for a select', () => {
        assert.deepEqual(optionsFor(catalogue, 'dispute_type'), [
            { value: 'PURITY_MISMATCH', label: 'اختلاف عیار' },
            { value: 'WEIGHT_MISMATCH', label: 'اختلاف وزن' },
        ]);
    });

    it('falls back to the raw value when the server has no label', () => {
        const [first] = optionsFor(catalogue, 'webhook_event_type');

        assert.equal(first.label, 'trade.executed');
    });

    it('is empty, never a throw, for an unknown key or a missing catalogue', () => {
        assert.deepEqual(optionsFor(catalogue, 'no_such_enum'), []);
        assert.deepEqual(optionsFor(EMPTY_CATALOGUE, 'dispute_type'), []);
        assert.deepEqual(optionsFor(null, 'dispute_type'), []);
        assert.deepEqual(optionsFor({ dispute_type: 'not an array' }, 'dispute_type'), []);
    });

    it('skips malformed entries rather than rendering an empty option', () => {
        assert.deepEqual(optionsFor({ x: [{ label: 'no value' }, { value: 'A', label: 'a' }] }, 'x'), [
            { value: 'A', label: 'a' },
        ]);
    });
});

describe('mapFor', () => {
    it('produces the {VALUE: label} shape StatusBadge takes', () => {
        assert.deepEqual(mapFor(catalogue, 'dispute_type'), {
            PURITY_MISMATCH: 'اختلاف عیار',
            WEIGHT_MISMATCH: 'اختلاف وزن',
        });
    });
});

describe('labelFor', () => {
    it('resolves a known value', () => {
        assert.equal(labelFor(catalogue, 'dispute_type', 'PURITY_MISMATCH'), 'اختلاف عیار');
    });

    it('shows an unknown value rather than hiding it — §1.11', () => {
        // A status the client has never seen is still information; an em dash
        // would make it indistinguishable from an absent one.
        assert.equal(labelFor(catalogue, 'dispute_type', 'NEWLY_ADDED'), 'NEWLY_ADDED');
        assert.equal(labelFor(EMPTY_CATALOGUE, 'dispute_type', 'PURITY_MISMATCH'), 'PURITY_MISMATCH');
    });

    it('uses the em dash only for a genuinely absent value', () => {
        assert.equal(labelFor(catalogue, 'dispute_type', null), '—');
        assert.equal(labelFor(catalogue, 'dispute_type', ''), '—');
    });
});

describe('restrictTo', () => {
    it('keeps only the allowed values, in the order asked for', () => {
        const restricted = restrictTo(optionsFor(catalogue, 'evidence_type'), ['DOCUMENT']);

        assert.deepEqual(restricted, [{ value: 'DOCUMENT', label: 'سند' }]);
    });

    it('drops an allowed value the catalogue does not have', () => {
        const restricted = restrictTo(optionsFor(catalogue, 'evidence_type'), ['DOCUMENT', 'PHOTO']);

        assert.deepEqual(restricted.map((option) => option.value), ['DOCUMENT']);
    });

    it('tolerates a missing allow-list', () => {
        assert.deepEqual(restrictTo(optionsFor(catalogue, 'evidence_type'), null), []);
    });
});

describe('groupByPrefix', () => {
    it('groups event names on the segment before the dot', () => {
        const groups = groupByPrefix(optionsFor(catalogue, 'webhook_event_type'));

        assert.deepEqual(groups.map((group) => group.group), ['trade', 'order']);
        assert.deepEqual(groups[1].options.map((option) => option.value), [
            'order.filled',
            'order.cancelled',
        ]);
    });

    it('puts a value with no separator in the unnamed group', () => {
        const groups = groupByPrefix([{ value: 'bare', label: 'bare' }]);

        assert.equal(groups[0].group, '');
    });
});
