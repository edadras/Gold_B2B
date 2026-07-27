/**
 * Evidence hashing — `POST /disputes/{id}/evidences`, `file_hash`.
 *
 * The endpoint's rule is `[0-9a-fA-F]{64}`, so the only two things that matter
 * are that the digest is right and that it comes out in that exact shape. A
 * wrong digest is worse than none: it would be filed as a tamper receipt that
 * fails when a mediator checks it.
 *
 *     node --test resources/js/lib/__tests__/digest.test.js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { canDigest, sha256Hex, toHex } from '../digest.js';

const encoder = new TextEncoder();

describe('toHex', () => {
    it('pads each byte to two lower-case characters', () => {
        assert.equal(toHex(new Uint8Array([0, 15, 16, 255]).buffer), '000f10ff');
    });
});

describe('sha256Hex', () => {
    it('matches the published digest of the empty input', async () => {
        assert.equal(
            await sha256Hex(new Uint8Array(0)),
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        );
    });

    it('matches the published digest of "abc"', async () => {
        assert.equal(
            await sha256Hex(encoder.encode('abc')),
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
        );
    });

    it('produces exactly what the endpoint\'s regex accepts', async () => {
        const hash = await sha256Hex(encoder.encode('گواهی ری‌گیری'));

        assert.match(hash, /^[0-9a-f]{64}$/);
    });

    it('accepts an ArrayBuffer as well as a view', async () => {
        const view = encoder.encode('abc');
        const buffer = view.buffer.slice(view.byteOffset, view.byteOffset + view.byteLength);

        assert.equal(
            await sha256Hex(buffer),
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
        );
    });
});

describe('canDigest', () => {
    it('is true wherever crypto.subtle exists', () => {
        // Node ≥ 18 exposes it globally, as does any secure browser origin.
        assert.equal(canDigest(), typeof crypto?.subtle?.digest === 'function');
    });
});
