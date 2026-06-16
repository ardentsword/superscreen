// All reads/writes to the layout API. Writes attach the operator's API key (kept
// in localStorage so the public page never embeds it); if the server requires a
// key we don't have (401), we prompt for one once and retry.
const API_KEY_STORE = 'superscreenKey';

export class Api {
    #layoutUrl;
    #tilesUrl;

    constructor(config) {
        this.#layoutUrl = config.layoutUrl;
        this.#tilesUrl = config.tilesUrl;
    }

    /** Fetch the layout snapshot. Pass the last ETag to get a 304 when unchanged. */
    getLayout(etag) {
        const headers = {};
        if (etag) {
            headers['If-None-Match'] = etag;
        }
        return fetch(this.#layoutUrl, { headers, cache: 'no-store' });
    }

    deleteTile(id) {
        return this.#write(`${this.#tilesUrl}/${encodeURIComponent(id)}`, { method: 'DELETE' });
    }

    moveTile(id, x, y) {
        return this.#write(`${this.#tilesUrl}/${encodeURIComponent(id)}/position`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ x, y }),
        });
    }

    /** Pin (PUT) or un-pin (DELETE) the reservation for a tile's spot. */
    setReservation(id, reserved) {
        return this.#write(`${this.#tilesUrl}/${encodeURIComponent(id)}/reservation`, {
            method: reserved ? 'DELETE' : 'PUT',
        });
    }

    #key() {
        return localStorage.getItem(API_KEY_STORE) ?? '';
    }

    /**
     * A write that attaches the stored API key if present. On 401 (auth is on and
     * our key is missing/stale) prompt for a key, save it, and retry once. In open
     * mode writes just succeed, so this never prompts.
     */
    async #write(url, options = {}) {
        const headers = { ...(options.headers ?? {}) };
        const key = this.#key();
        if (key) {
            headers['X-Api-Key'] = key;
        }

        let response = await fetch(url, { ...options, cache: 'no-store', headers });
        if (response.status !== 401) {
            return response;
        }

        const entered = window.prompt('An API key is required for this action. Enter your API key:', '');
        if (!entered) {
            return response; // cancelled — the next poll re-syncs
        }
        localStorage.setItem(API_KEY_STORE, entered.trim());

        response = await fetch(url, {
            ...options,
            cache: 'no-store',
            headers: { ...(options.headers ?? {}), 'X-Api-Key': entered.trim() },
        });
        if (response.status === 401) {
            window.alert('That API key was rejected.');
        }
        return response;
    }
}
