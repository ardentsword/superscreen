// The display: poll the layout snapshot and reconcile the grid keyed by tile id,
// so unchanged tiles (notably playing video / loaded iframes) are never torn
// down. See docs/FRONTEND.md.
import { config } from './config.js';
import {
    applyPosition,
    createPlaceholder,
    createTile,
    RES_PREFIX,
    updateTile,
} from './tile.js';

export class Display {
    #screen;
    #api;
    #grid;
    #drag;
    #nodes = new Map(); // id (or RES_PREFIX+id) -> node record
    #etag = null;

    constructor({ screen, api, grid, drag }) {
        this.#screen = screen;
        this.#api = api;
        this.#grid = grid;
        this.#drag = drag;
        this.poll = this.poll.bind(this); // used as the interval + refresh callback
    }

    start() {
        this.poll();
        setInterval(this.poll, config.pollInterval * 1000);
    }

    async poll() {
        if (this.#drag.isDragging()) {
            return; // don't reconcile mid-drag
        }
        try {
            const response = await this.#api.getLayout(this.#etag);
            if (response.status === 304) {
                return; // unchanged
            }
            if (!response.ok) {
                return; // keep the last good layout on a bad response
            }
            this.#etag = response.headers.get('ETag') ?? this.#etag;
            this.#reconcile(await response.json());
        } catch {
            // Network hiccup: keep the last good layout, try again next tick.
        }
    }

    #reconcile(layout) {
        this.#grid.apply(layout.grid);

        // Collaborators a tile's buttons need; refresh re-polls after a write.
        const ctx = { api: this.#api, drag: this.#drag, refresh: this.poll };
        const seen = new Set();
        const reservedIds = new Set((layout.reservations ?? []).map((r) => r.id));

        for (const tile of layout.tiles) {
            seen.add(tile.id);
            const existing = this.#nodes.get(tile.id);
            if (!existing) {
                const node = createTile(tile, ctx, reservedIds.has(tile.id));
                this.#screen.appendChild(node.el);
                this.#nodes.set(tile.id, node);
            } else {
                updateTile(existing, tile, reservedIds.has(tile.id));
            }
        }

        // Reserved spots with no live tile: render a placeholder with an un-pin button.
        for (const reservation of layout.reservations ?? []) {
            if (seen.has(reservation.id)) {
                continue; // a live tile already occupies it
            }
            const key = RES_PREFIX + reservation.id;
            seen.add(key);
            const existing = this.#nodes.get(key);
            if (!existing) {
                const el = createPlaceholder(reservation.id, reservation.position, ctx);
                this.#screen.appendChild(el);
                this.#nodes.set(key, { el });
            } else {
                applyPosition(existing.el, reservation.position);
            }
        }

        for (const [id, node] of this.#nodes) {
            if (!seen.has(id)) {
                node.el.remove();
                this.#nodes.delete(id);
            }
        }
    }
}
