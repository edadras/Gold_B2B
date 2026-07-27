/**
 * SHA-256 of a file, computed in the browser.
 *
 * WHY THE CLIENT COMPUTES THIS
 * ----------------------------
 * `POST /disputes/{id}/evidences` takes an optional `file_hash`, described by
 * SubmitEvidenceRequest as «the promise that the bytes have not changed since».
 * The value only means anything if it is taken from the bytes the member
 * actually holds — a hash the server computed from its own copy proves nothing
 * about the member's copy, which is the artefact a mediator will be shown.
 *
 * `crypto.subtle` is only exposed on a secure origin. Rather than fall back to
 * a hand-rolled SHA-256 — several hundred lines whose bugs would be invisible
 * until a mediator rejected a member's evidence — this returns null and the
 * caller files the evidence without a hash, which the endpoint permits.
 *
 * @module lib/digest
 */

/** Lower-case hex, the format `file_hash`'s `[0-9a-fA-F]{64}` rule expects. */
export function toHex(buffer) {
    return [...new Uint8Array(buffer)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

/** True when this origin can hash at all — lets a form hide the promise it cannot keep. */
export function canDigest() {
    return typeof crypto !== 'undefined'
        && typeof crypto.subtle === 'object'
        && crypto.subtle !== null
        && typeof crypto.subtle.digest === 'function';
}

/**
 * @param {ArrayBuffer|Uint8Array} bytes
 * @returns {Promise<string|null>} 64 hex characters, or null where unavailable
 */
export async function sha256Hex(bytes) {
    if (! canDigest()) {
        return null;
    }

    const source = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);

    try {
        // A fresh copy: some engines detach the view's buffer during digest,
        // and the caller may still want to upload the same bytes afterwards.
        const digest = await crypto.subtle.digest('SHA-256', source.slice());

        return toHex(digest);
    } catch {
        return null;
    }
}

/** The same, straight from an `<input type="file">` selection. */
export async function sha256OfFile(file) {
    if (! file || typeof file.arrayBuffer !== 'function') {
        return null;
    }

    return sha256Hex(await file.arrayBuffer());
}
