// Grid geometry from the last layout snapshot; maps a pointer position to a
// snapped cell (used by drag-to-move).
export class Grid {
    cols = 1;
    rows = 1;
    gap = 8;
    #screen;

    constructor(screen) {
        this.#screen = screen;
    }

    /** Apply the `grid` block from a layout snapshot: store geometry + CSS vars. */
    apply({ cols, rows, gap }) {
        this.cols = cols;
        this.rows = rows;
        this.gap = gap ?? 8;
        this.#screen.style.setProperty('--cols', cols);
        this.#screen.style.setProperty('--rows', rows);
        this.#screen.style.setProperty('--gap', `${this.gap}px`);
    }

    /** Snap a pointer position to a cell, clamped so the footprint stays in bounds. */
    cellFromPointer(clientX, clientY, w, h) {
        const rect = this.#screen.getBoundingClientRect();
        const cellW = (rect.width - this.gap * (this.cols + 1)) / this.cols;
        const cellH = (rect.height - this.gap * (this.rows + 1)) / this.rows;
        const col = Math.floor((clientX - rect.left - this.gap) / (cellW + this.gap));
        const row = Math.floor((clientY - rect.top - this.gap) / (cellH + this.gap));
        return {
            col: Math.max(0, Math.min(col, this.cols - w)),
            row: Math.max(0, Math.min(row, this.rows - h)),
        };
    }
}
