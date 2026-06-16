// Building and updating tile DOM nodes (content + the corner controls), plus the
// placeholder for a held-but-empty reserved spot.
//
// `ctx` carries the collaborators a tile's buttons need: { api, drag, refresh }.
import { MOVE_SVG, PIN_SVG } from './icons.js';
import { applyStatus, createStatusBadge } from './status.js';

// node-map key prefix for reserved-but-empty placeholders (so they can't collide
// with a real tile id).
export const RES_PREFIX = ' res:';

export function applyPosition(el, position) {
    el.style.setProperty('--x', position.x + 1); // CSS grid lines are 1-based
    el.style.setProperty('--y', position.y + 1);
    el.style.setProperty('--w', position.w);
    el.style.setProperty('--h', position.h);
}

export function applyPin(el, reserved) {
    el.classList.toggle('reserved', reserved);
}

/** A wrapper holding the server-rendered content HTML (Twig, per type). */
function makeContentEl(html) {
    const el = document.createElement('div');
    el.className = 'tile-content';
    el.innerHTML = html; // rendered + escaped server-side (see templates/tile/)
    return el;
}

/** A small delete cross in the tile's corner for manual removal. */
function makeDeleteButton(id, { api, refresh }) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tile-delete';
    button.textContent = '×';
    button.title = 'Delete tile';
    button.addEventListener('click', async (event) => {
        event.stopPropagation();
        try {
            await api.deleteTile(id);
        } catch {
            // ignore; the next poll reflects the real state anyway
        }
        refresh(); // refresh now instead of waiting for the next tick
    });
    return button;
}

/** Drag handle (grip dots); wires into the shared drag controller. */
function makeMoveButton(id, { drag }) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tile-move';
    button.title = 'Drag to move';
    button.innerHTML = MOVE_SVG;
    drag.attach(button, id);
    return button;
}

/** Pin/reserve toggle; reserves the tile's current spot (or releases it). */
function makePinButton(id, { api, refresh }) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tile-pin';
    button.title = 'Pin: reserve this spot for this tile';
    button.innerHTML = PIN_SVG;
    button.addEventListener('click', async (event) => {
        event.stopPropagation();
        const reserved = button.closest('.tile')?.classList.contains('reserved');
        try {
            await api.setReservation(id, reserved);
        } catch {
            // ignore; the next poll re-syncs
        }
        refresh();
    });
    return button;
}

/**
 * Create a fresh tile node and its bookkeeping record
 * ({ el, contentEl, statusEl, contentKey }).
 */
export function createTile(tile, ctx, reserved) {
    const el = document.createElement('div');
    el.className = 'tile';
    el.dataset.id = tile.id;

    const contentEl = makeContentEl(tile.html);
    const statusEl = createStatusBadge();
    applyStatus(statusEl, tile);

    el.append(
        contentEl,
        makeDeleteButton(tile.id, ctx),
        statusEl,
        makeMoveButton(tile.id, ctx),
        makePinButton(tile.id, ctx),
    );
    applyPin(el, reserved);
    applyPosition(el, tile.position);

    return { el, contentEl, statusEl, contentKey: tile.html };
}

/**
 * Update an existing tile node in place. Content is rebuilt only when the
 * rendered HTML actually changed, so a playing video / loaded iframe survives.
 */
export function updateTile(node, tile, reserved) {
    if (node.contentKey !== tile.html) {
        node.contentEl.innerHTML = tile.html;
        node.contentKey = tile.html;
    }
    applyStatus(node.statusEl, tile);
    applyPin(node.el, reserved);
    applyPosition(node.el, tile.position);
}

/** Faint placeholder for a held (empty) reserved spot, with an un-pin button. */
export function createPlaceholder(id, position, { api, refresh }) {
    const el = document.createElement('div');
    el.className = 'tile reservation-placeholder';
    el.dataset.id = id;
    applyPosition(el, position);

    const label = document.createElement('div');
    label.className = 'placeholder-label';
    label.textContent = 'reserved';

    const unpin = document.createElement('button');
    unpin.type = 'button';
    unpin.className = 'tile-delete';
    unpin.title = 'Release this reservation';
    unpin.textContent = '×';
    unpin.addEventListener('click', async (event) => {
        event.stopPropagation();
        try {
            await api.setReservation(id, true); // true => release
        } catch {
            // ignore
        }
        refresh();
    });

    el.append(label, unpin);
    return el;
}
