/**
 * The single point of contact with `/api/v1` — doc §1.2, `Composables/`.
 *
 * Everything the panel knows about the server passes through here: the bearer
 * token, the envelope shape of §1.4, the error shape of §1.5, cursor
 * pagination from §1.8 and idempotency from §1.10. Components never call
 * `fetch` directly, which is what makes it possible to change the transport
 * (or, later, to swap this for an Inertia/axios pairing) in one file.
 *
 * @module lib/api
 */

/**
 * A business error from the API — §1.5. Carries the machine-readable code so
 * callers can branch on `INSUFFICIENT_GOLD` rather than on a Persian string,
 * and `fieldErrors` so a form can attach messages to inputs.
 */
export class ApiError extends Error {
    constructor(status, body) {
        const error = (body && body.error) || {};
        super(error.message || `HTTP ${status}`);
        this.name = 'ApiError';
        this.status = status;
        this.code = error.code || 'INTERNAL_ERROR';
        this.messageEn = error.message_en || null;
        this.details = error.details || null;
        this.fieldErrors = error.field_errors || null;
    }

    /** True when retrying the identical request may succeed. */
    get isRetryable() {
        return this.status === 409 || this.status === 429 || this.status >= 500;
    }
}

/** Raised when the session is gone; the shell listens for it and redirects. */
export class UnauthenticatedError extends ApiError {}

/**
 * RFC 4122 v4, from `crypto.randomUUID` where available.
 *
 * The fallback exists because `randomUUID` is only exposed on secure origins,
 * and the panel is developed over plain HTTP. It still draws from
 * `crypto.getRandomValues`, so the key is unguessable either way — which
 * matters, since an idempotency key that collides with another member's would
 * replay their order.
 */
export function uuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Encode a filter/sort/cursor bag into §1.9's query syntax. */
export function buildQuery(params = {}) {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (key === 'filter' && typeof value === 'object') {
            for (const [name, filterValue] of Object.entries(value)) {
                if (filterValue !== null && filterValue !== undefined && filterValue !== '') {
                    search.append(`filter[${name}]`, String(filterValue));
                }
            }
            continue;
        }

        search.append(key, String(value));
    }

    const query = search.toString();

    return query === '' ? '' : `?${query}`;
}

export class ApiClient {
    /**
     * @param {{baseUrl?: string, token?: string|null, csrfToken?: string|null}} options
     */
    constructor({ baseUrl = '/api/v1', token = null, csrfToken = null } = {}) {
        this.baseUrl = baseUrl;
        this.token = token;
        this.csrfToken = csrfToken;
        /** @type {Set<(error: Error) => void>} */
        this.errorListeners = new Set();
    }

    setToken(token) {
        this.token = token;
    }

    /** Register a handler for auth failures; the shell uses it to sign out. */
    onError(listener) {
        this.errorListeners.add(listener);
        return () => this.errorListeners.delete(listener);
    }

    async request(method, path, { body = null, query = null, idempotencyKey = null, signal = null } = {}) {
        const headers = {
            Accept: 'application/json',
            'Accept-Language': 'fa',
            'X-Request-Id': uuid(),
        };

        if (this.token) {
            headers.Authorization = `Bearer ${this.token}`;
        }

        if (body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        // §1.10: mandatory on financial POST/PUT. Generated once by the caller
        // when the form opens and reused across every retry of that form, so a
        // resubmitted order is recognised as the same order.
        if (idempotencyKey) {
            headers['Idempotency-Key'] = idempotencyKey;
        }

        const url = this.baseUrl + path + (query ? buildQuery(query) : '');

        let response;
        try {
            response = await fetch(url, {
                method,
                headers,
                credentials: 'same-origin',
                body: body === null ? undefined : JSON.stringify(body),
                signal,
            });
        } catch (cause) {
            if (cause && cause.name === 'AbortError') {
                throw cause;
            }
            const offline = new ApiError(0, {
                error: { code: 'SERVICE_UNAVAILABLE', message: 'ارتباط با سرور برقرار نشد.' },
            });
            this.#notify(offline);
            throw offline;
        }

        if (response.status === 204) {
            return { data: null, meta: null, links: null };
        }

        const text = await response.text();
        let payload = null;
        if (text !== '') {
            try {
                payload = JSON.parse(text);
            } catch {
                payload = null;
            }
        }

        if (!response.ok) {
            const error = response.status === 401
                ? new UnauthenticatedError(response.status, payload)
                : new ApiError(response.status, payload);
            this.#notify(error);
            throw error;
        }

        return {
            data: payload ? payload.data : null,
            meta: payload ? payload.meta || null : null,
            links: payload ? payload.links || null : null,
            replayed: response.headers.get('X-Idempotent-Replay') === 'true',
        };
    }

    get(path, query = null, options = {}) {
        return this.request('GET', path, { query, ...options });
    }

    post(path, body = null, options = {}) {
        return this.request('POST', path, { body, ...options });
    }

    put(path, body = null, options = {}) {
        return this.request('PUT', path, { body, ...options });
    }

    delete(path, options = {}) {
        return this.request('DELETE', path, options);
    }

    /**
     * Panel-local endpoints under `/app`, which are session-authenticated and
     * therefore need the CSRF token rather than the bearer token.
     */
    async panel(method, path, body = null) {
        const headers = { Accept: 'application/json' };

        if (this.csrfToken) {
            headers['X-CSRF-TOKEN'] = this.csrfToken;
        }

        if (body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(path, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === null ? undefined : JSON.stringify(body),
        });

        const text = await response.text();
        const payload = text === '' ? null : JSON.parse(text);

        if (!response.ok) {
            throw new ApiError(response.status, payload);
        }

        return payload ? payload.data : null;
    }

    #notify(error) {
        for (const listener of this.errorListeners) {
            listener(error);
        }
    }
}

/** The instance the whole panel shares, created by the shell at boot. */
export let api = new ApiClient();

export function configureApi(options) {
    api = new ApiClient(options);
    return api;
}
