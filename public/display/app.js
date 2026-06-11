// SuperScreen display: poll the layout snapshot and reconcile the grid by tile
// id, so unchanged tiles (notably playing video / loaded iframes) are never
// torn down. See docs/FRONTEND.md.

const config = window.SUPERSCREEN ?? { layoutUrl: '/api/layout', pollInterval: 3 };
const screen = document.getElementById('screen');

/** id -> { el, contentKey } */
const nodes = new Map();
let etag = null;

/** Build a DOM node for a content payload ({ type, ...fields }). */
function renderContent(content) {
    switch (content.type) {
        case 'image': {
            const el = document.createElement('img');
            el.src = content.src ?? '';
            el.alt = '';
            return el;
        }
        case 'video': {
            const el = document.createElement('video');
            el.src = content.src ?? '';
            el.muted = true;        // required for autoplay
            el.autoplay = true;
            el.loop = true;
            el.playsInline = true;
            return el;
        }
        case 'iframe': {
            const el = document.createElement('iframe');
            el.src = content.src ?? '';
            el.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-forms');
            return el;
        }
        case 'html': {
            const el = document.createElement('div');
            el.className = 'html';
            // Canonical field is `html`; accept `src` as a lenient fallback.
            el.innerHTML = content.html ?? content.src ?? ''; // trusted callers only
            return el;
        }
        case 'text': {
            const el = document.createElement('div');
            el.className = 'text';
            el.textContent = content.text ?? '';
            return el;
        }
        default: {
            const el = document.createElement('div');
            el.className = 'unknown';
            el.textContent = `Unknown content type: ${content.type}`;
            return el;
        }
    }
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

function reconcile(layout) {
    screen.style.setProperty('--cols', layout.grid.cols);
    screen.style.setProperty('--rows', layout.grid.rows);
    screen.style.setProperty('--gap', `${layout.grid.gap ?? 8}px`);

    const seen = new Set();

    for (const tile of layout.tiles) {
        seen.add(tile.id);
        const contentKey = JSON.stringify(tile.content);
        const existing = nodes.get(tile.id);

        if (!existing) {
            const el = document.createElement('div');
            el.className = 'tile';
            el.dataset.id = tile.id;
            const contentEl = renderContent(tile.content);
            el.append(contentEl, makeDeleteButton(tile.id));
            applyPosition(el, tile.position);
            screen.appendChild(el);
            nodes.set(tile.id, { el, contentEl, contentKey });
            continue;
        }

        // Only rebuild content when it actually changed; otherwise leave the
        // node alone (keeps video playing, iframe loaded). The delete button is
        // a separate child, so it survives content swaps.
        if (existing.contentKey !== contentKey) {
            const contentEl = renderContent(tile.content);
            existing.contentEl.replaceWith(contentEl);
            existing.contentEl = contentEl;
            existing.contentKey = contentKey;
        }
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
