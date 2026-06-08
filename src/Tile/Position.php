<?php

declare(strict_types=1);

namespace App\Tile;

/**
 * A tile's resolved placement on the grid, in cells. This is part of the
 * internal model: w/h are derived from the Size preset and x/y are assigned by
 * the backend's placement step. See docs/README.md §4.2.
 */
final readonly class Position
{
    public function __construct(
        public int $x,
        public int $y,
        public int $w,
        public int $h,
    ) {}

    /**
     * @return array{x: int, y: int, w: int, h: int}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h];
    }

    /**
     * @param array{x: int, y: int, w: int, h: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['x'], (int) $data['y'], (int) $data['w'], (int) $data['h']);
    }
}
