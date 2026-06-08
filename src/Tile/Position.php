<?php

declare(strict_types=1);

namespace App\Tile;

/**
 * A tile's resolved placement on the grid, in cells. Part of the internal model:
 * w/h are derived from the Size preset and x/y are assigned by the backend's
 * placement step. See docs/README.md §4.2.
 */
final readonly class Position
{
    public function __construct(
        public int $x,
        public int $y,
        public int $w,
        public int $h,
    ) {}
}
