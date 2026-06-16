// Drag-to-move controller. Owns the active drag; while one is in progress the
// Display pauses polling (it checks isDragging) so reconcile can't fight it. On
// drop it commits the new cell via the API; the server stays authoritative.
export class DragController {
    #grid;
    #api;
    #refresh;
    #drag = null;

    constructor({ grid, api, refresh }) {
        this.#grid = grid;
        this.#api = api;
        this.#refresh = refresh;
    }

    isDragging() {
        return this.#drag !== null;
    }

    /** Wire a move-handle button so pressing it starts dragging its tile. */
    attach(button, id) {
        button.addEventListener('pointerdown', (event) => this.#start(event, id));
    }

    #start(event, id) {
        const el = event.currentTarget.closest('.tile');
        if (!el) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        const handle = event.currentTarget;
        this.#drag = {
            id,
            el,
            handle,
            w: Number(el.style.getPropertyValue('--w')) || 1,
            h: Number(el.style.getPropertyValue('--h')) || 1,
            col: null,
            row: null,
        };
        el.classList.add('dragging');
        handle.setPointerCapture(event.pointerId);
        handle.addEventListener('pointermove', this.#onMove);
        handle.addEventListener('pointerup', this.#onEnd, { once: true });
        handle.addEventListener('pointercancel', this.#onEnd, { once: true });
    }

    // Arrow fields keep a stable identity for add/removeEventListener and bind `this`.
    #onMove = (event) => {
        const drag = this.#drag;
        if (!drag) {
            return;
        }
        const { col, row } = this.#grid.cellFromPointer(event.clientX, event.clientY, drag.w, drag.h);
        drag.col = col;
        drag.row = row;
        drag.el.style.setProperty('--x', col + 1); // live preview via the grid vars
        drag.el.style.setProperty('--y', row + 1);
    };

    #onEnd = async () => {
        const drag = this.#drag;
        if (!drag) {
            return;
        }
        drag.handle.removeEventListener('pointermove', this.#onMove);
        drag.el.classList.remove('dragging');
        this.#drag = null;

        if (drag.col !== null) {
            try {
                await this.#api.moveTile(drag.id, drag.col, drag.row);
            } catch {
                // ignore; the refresh re-syncs from authoritative server state
            }
        }
        this.#refresh();
    };
}
