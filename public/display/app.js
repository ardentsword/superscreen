// SuperScreen display: poll the layout snapshot and reconcile the grid by tile
// id, so unchanged tiles (notably playing video / loaded iframes) are never
// torn down. See docs/FRONTEND.md.

const config = window.SUPERSCREEN ?? { layoutUrl: '/api/layout', pollInterval: 3 };
const screen = document.getElementById('screen');

/** id -> { el, contentKey } */
const nodes = new Map();
let etag = null;

/** A wrapper holding the server-rendered content HTML (Twig, per type). */
function makeContentEl(html) {
    const el = document.createElement('div');
    el.className = 'tile-content';
    el.innerHTML = html; // rendered + escaped server-side (see templates/tile/)
    return el;
}

function applyPosition(el, position) {
    el.style.setProperty('--x', position.x + 1); // CSS grid lines are 1-based
    el.style.setProperty('--y', position.y + 1);
    el.style.setProperty('--w', position.w);
    el.style.setProperty('--h', position.h);
}

// Base for tile mutations, derived from the layout URL ("/api/layout" -> "/api/tiles").
const tilesUrl = config.layoutUrl.replace(/layout$/, 'tiles');

/** A small delete cross in the tile's corner for manual removal. */
function makeDeleteButton(id) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tile-delete';
    button.textContent = '×'; // ×
    button.title = 'Delete tile';
    button.addEventListener('click', async (event) => {
        event.stopPropagation();
        try {
            await fetch(`${tilesUrl}/${encodeURIComponent(id)}`, { method: 'DELETE' });
        } catch {
            // ignore; the next poll reflects the real state anyway
        }
        poll(); // refresh now instead of waiting for the next tick
    });
    return button;
}

function formatRemaining(seconds) {
    if (seconds <= 0) return 'expiring…';
    if (seconds < 60) return `expires in ${seconds}s`;
    const m = Math.floor(seconds / 60);
    if (m < 60) return `expires in ${m}m ${seconds % 60}s`;
    return `expires in ${Math.floor(m / 60)}h ${m % 60}m`;
}

/**
 * A status badge next to the delete cross: a pie that "spins down" (full at
 * creation, gone at expiry) for timed tiles, or ∞ for permanent ones. The
 * depletion is a pure-CSS animation; we set its duration once and use a negative
 * animation-delay so it resumes at the right point (even after a reload).
 */
function makeStatusBadge() {
    const badge = document.createElement('span');
    badge.className = 'tile-status';
    return badge;
}

function applyStatus(badge, tile) {
    const timed = tile.expires_at != null;
    // A signature so we only (re)configure when the tile's lifetime changes —
    // otherwise resetting the animation each poll would make it jump.
    const sig = timed ? `${tile.created_at}:${tile.expires_at}` : 'permanent';

    if (badge.dataset.sig !== sig) {
        badge.dataset.sig = sig;
        badge.dataset.mode = timed ? 'timed' : 'permanent';

        if (timed) {
            const total = Math.max(1, tile.expires_at - tile.created_at);
            const elapsed = Math.max(0, Math.floor(Date.now() / 1000) - tile.created_at);
            badge.textContent = '';
            badge.style.animation = 'none';
            void badge.offsetWidth; // reflow so a re-used node restarts cleanly
            badge.style.animation = `tile-spin-down ${total}s linear forwards`;
            badge.style.animationDelay = `-${elapsed}s`;
        } else {
            badge.style.animation = 'none';
            badge.textContent = '∞';
        }
    }

    badge.title = timed
        ? formatRemaining(tile.expires_at - Math.floor(Date.now() / 1000))
        : 'No timeout';
}

function reconcile(layout) {
    screen.style.setProperty('--cols', layout.grid.cols);
    screen.style.setProperty('--rows', layout.grid.rows);
    screen.style.setProperty('--gap', `${layout.grid.gap ?? 8}px`);

    const seen = new Set();

    for (const tile of layout.tiles) {
        seen.add(tile.id);
        const contentKey = tile.html; // server-rendered HTML is the content signature
        const existing = nodes.get(tile.id);

        if (!existing) {
            const el = document.createElement('div');
            el.className = 'tile';
            el.dataset.id = tile.id;
            const contentEl = makeContentEl(tile.html);
            const statusEl = makeStatusBadge();
            applyStatus(statusEl, tile);
            el.append(contentEl, makeDeleteButton(tile.id), statusEl);
            applyPosition(el, tile.position);
            screen.appendChild(el);
            nodes.set(tile.id, { el, contentEl, statusEl, contentKey });
            continue;
        }

        // Only rebuild content when the rendered HTML actually changed; otherwise
        // leave it alone (keeps video playing, iframe loaded). The delete button
        // and status badge are separate children, so they survive content swaps.
        if (existing.contentKey !== contentKey) {
            existing.contentEl.innerHTML = tile.html;
            existing.contentKey = contentKey;
        }
        applyStatus(existing.statusEl, tile); // refresh timeout indicator
        applyPosition(existing.el, tile.position);
    }

    for (const [id, node] of nodes) {
        if (!seen.has(id)) {
            node.el.remove();
            nodes.delete(id);
        }
    }
}

async function poll() {
    try {
        const headers = {};
        if (etag) {
            headers['If-None-Match'] = etag;
        }

        const response = await fetch(config.layoutUrl, { headers, cache: 'no-store' });
        if (response.status === 304) {
            return; // unchanged
        }
        if (!response.ok) {
            return; // keep the last good layout on a bad response
        }

        etag = response.headers.get('ETag') ?? etag;
        reconcile(await response.json());
    } catch {
        // Network hiccup: keep the last good layout, try again next tick.
    }
}

poll();
setInterval(poll, Math.max(1, config.pollInterval) * 1000);
